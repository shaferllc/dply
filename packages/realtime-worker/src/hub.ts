/// <reference types="@cloudflare/workers-types" />

// AppHub — one Durable Object instance per app id. It owns every live
// WebSocket connection for that app and fans out channel + presence messages.
// Connections use the WebSocket Hibernation API: per-connection state lives in
// each socket's attachment, so the DO can evict from memory and rebuild any
// view (subscribers, presence rosters) by scanning getWebSockets().

import {
  buildPresencePayload,
  encode,
  isClientEvent,
  isPresenceChannel,
  channelRequiresAuth,
  makeSocketId,
  type InboundMessage,
  type PresenceMember,
} from './protocol';
import { verifyChannelAuth, type AppCredentials } from './auth';
import type { Env } from './types';

interface ConnState {
  socketId: string;
  // channel name -> presence member (presence channels) or null (public/private)
  channels: Record<string, PresenceMember | null>;
}

interface PublishPayload {
  name: string;
  channels?: string[];
  channel?: string;
  data?: unknown;
  socket_id?: string;
}

export class AppHub implements DurableObject {
  constructor(
    private readonly state: DurableObjectState,
    private readonly env: Env,
  ) {}

  async fetch(request: Request): Promise<Response> {
    const url = new URL(request.url);

    // Internal calls from the edge Worker (already authenticated there).
    if (url.pathname === '/internal/publish') {
      return this.handlePublish(request);
    }
    if (url.pathname === '/internal/stats') {
      return this.handleStats();
    }
    if (url.pathname === '/internal/stats/reset') {
      return this.handleStatsReset();
    }

    if (request.headers.get('Upgrade') !== 'websocket') {
      return new Response('expected websocket', { status: 426 });
    }
    return this.handleConnect(request);
  }

  // --- WebSocket lifecycle ------------------------------------------------

  private async handleConnect(request: Request): Promise<Response> {
    // The edge Worker forwards the resolved app credentials as headers. Persist
    // them so hibernated message handlers can verify channel auth.
    const creds: AppCredentials = {
      id: request.headers.get('X-App-Id') ?? '',
      key: request.headers.get('X-App-Key') ?? '',
      secret: request.headers.get('X-App-Secret') ?? '',
      enabled: true,
    };
    await this.state.storage.put('app', creds);

    const pair = new WebSocketPair();
    const client = pair[0];
    const server = pair[1];

    this.state.acceptWebSocket(server);
    const socketId = makeSocketId();
    const conn: ConnState = { socketId, channels: {} };
    server.serializeAttachment(conn);
    await this.recordPeak();

    server.send(
      encode('pusher:connection_established', undefined, {
        socket_id: socketId,
        activity_timeout: 120,
      }),
    );

    return new Response(null, { status: 101, webSocket: client });
  }

  // --- Usage stats (peak concurrent connections, for billing) -------------

  /** Update the high-water mark of concurrent connections for this window. */
  private async recordPeak(): Promise<void> {
    const current = this.state.getWebSockets().length;
    const peak = (await this.state.storage.get<number>('peakConnections')) ?? 0;
    if (current > peak) {
      await this.state.storage.put('peakConnections', current);
    }
  }

  private async handleStats(): Promise<Response> {
    const current = this.state.getWebSockets().length;
    const stored = (await this.state.storage.get<number>('peakConnections')) ?? 0;
    return Response.json({
      connections: current,
      peakConnections: Math.max(current, stored),
    });
  }

  /** Reset the peak to the current live count — dply calls this per billing window. */
  private async handleStatsReset(): Promise<Response> {
    const current = this.state.getWebSockets().length;
    await this.state.storage.put('peakConnections', current);
    return Response.json({ ok: true, peakConnections: current });
  }

  async webSocketMessage(ws: WebSocket, raw: string | ArrayBuffer): Promise<void> {
    let msg: InboundMessage;
    try {
      msg = JSON.parse(typeof raw === 'string' ? raw : new TextDecoder().decode(raw));
    } catch {
      return; // ignore malformed frames, matching Pusher leniency
    }

    const event = msg.event ?? '';
    if (event === 'pusher:ping') {
      ws.send(encode('pusher:pong', undefined, {}));
      return;
    }
    if (event === 'pusher:subscribe') {
      await this.handleSubscribe(ws, this.objectData(msg.data));
      return;
    }
    if (event === 'pusher:unsubscribe') {
      this.handleUnsubscribe(ws, String(this.objectData(msg.data).channel ?? msg.channel ?? ''));
      return;
    }
    if (isClientEvent(event)) {
      this.handleClientEvent(ws, event, msg);
      return;
    }
  }

  async webSocketClose(ws: WebSocket): Promise<void> {
    this.dropConnection(ws);
  }

  async webSocketError(ws: WebSocket): Promise<void> {
    this.dropConnection(ws);
  }

  // --- Subscribe / unsubscribe -------------------------------------------

  private async handleSubscribe(ws: WebSocket, data: Record<string, unknown>): Promise<void> {
    const conn = ws.deserializeAttachment() as ConnState;
    const channel = String(data.channel ?? '');
    if (!channel) {
      return;
    }

    if (channelRequiresAuth(channel)) {
      const app = await this.appCreds();
      const channelData = isPresenceChannel(channel) ? String(data.channel_data ?? '') : undefined;
      const ok = await verifyChannelAuth(
        String(data.auth ?? ''),
        app.key,
        app.secret,
        conn.socketId,
        channel,
        channelData,
      );
      if (!ok) {
        ws.send(
          encode('pusher:error', channel, {
            code: 4009,
            message: `Connection not authorized for ${channel}`,
          }),
        );
        return;
      }
    }

    let member: PresenceMember | null = null;
    if (isPresenceChannel(channel)) {
      try {
        const parsed = JSON.parse(String(data.channel_data ?? '{}')) as {
          user_id: unknown;
          user_info?: unknown;
        };
        member = { user_id: String(parsed.user_id), user_info: parsed.user_info };
      } catch {
        ws.send(encode('pusher:error', channel, { code: 4009, message: 'Invalid channel_data' }));
        return;
      }
    }

    conn.channels[channel] = member;
    ws.serializeAttachment(conn);

    if (isPresenceChannel(channel) && member) {
      const members = this.presenceMembers(channel);
      ws.send(encode('pusher_internal:subscription_succeeded', channel, buildPresencePayload(members)));
      // Tell everyone else only if this user wasn't already present.
      if (!this.userHasOtherConnection(channel, member.user_id, conn.socketId)) {
        this.broadcast(
          channel,
          encode('pusher_internal:member_added', channel, {
            user_id: member.user_id,
            user_info: member.user_info,
          }),
          conn.socketId,
        );
      }
    } else {
      ws.send(encode('pusher_internal:subscription_succeeded', channel, {}));
    }
  }

  private handleUnsubscribe(ws: WebSocket, channel: string): void {
    if (!channel) {
      return;
    }
    const conn = ws.deserializeAttachment() as ConnState;
    const member = conn.channels[channel];
    if (!(channel in conn.channels)) {
      return;
    }
    delete conn.channels[channel];
    ws.serializeAttachment(conn);

    if (member && isPresenceChannel(channel) && !this.userHasOtherConnection(channel, member.user_id, conn.socketId)) {
      this.broadcast(
        channel,
        encode('pusher_internal:member_removed', channel, { user_id: member.user_id }),
        conn.socketId,
      );
    }
  }

  private handleClientEvent(ws: WebSocket, event: string, msg: InboundMessage): void {
    const conn = ws.deserializeAttachment() as ConnState;
    const channel = String(msg.channel ?? '');
    // Client events are only allowed on subscribed private/presence channels.
    if (!channel || !(channel in conn.channels) || !channelRequiresAuth(channel)) {
      return;
    }
    this.broadcast(channel, encode(event, channel, msg.data), conn.socketId);
  }

  // --- Publish (server-triggered events) ---------------------------------

  private async handlePublish(request: Request): Promise<Response> {
    let payload: PublishPayload;
    try {
      payload = (await request.json()) as PublishPayload;
    } catch {
      return Response.json({ error: 'invalid_body' }, { status: 400 });
    }
    const channels = payload.channels ?? (payload.channel ? [payload.channel] : []);
    for (const channel of channels) {
      this.broadcast(channel, encode(payload.name, channel, payload.data), payload.socket_id);
    }
    return Response.json({ ok: true, channels: channels.length });
  }

  // --- Helpers ------------------------------------------------------------

  private async appCreds(): Promise<AppCredentials> {
    return (
      (await this.state.storage.get<AppCredentials>('app')) ?? {
        id: '',
        key: '',
        secret: '',
        enabled: false,
      }
    );
  }

  /** Send a frame to every socket subscribed to `channel`, except `exceptSocketId`. */
  private broadcast(channel: string, frame: string, exceptSocketId?: string): void {
    for (const ws of this.state.getWebSockets()) {
      const conn = this.attachmentOf(ws);
      if (!conn || !(channel in conn.channels)) {
        continue;
      }
      if (exceptSocketId && conn.socketId === exceptSocketId) {
        continue;
      }
      try {
        ws.send(frame);
      } catch {
        // socket closing mid-broadcast; ignore
      }
    }
  }

  /** Distinct presence members currently in `channel`, keyed by user_id. */
  private presenceMembers(channel: string): Map<string, unknown> {
    const members = new Map<string, unknown>();
    for (const ws of this.state.getWebSockets()) {
      const conn = this.attachmentOf(ws);
      const member = conn?.channels[channel];
      if (member) {
        members.set(member.user_id, member.user_info ?? {});
      }
    }
    return members;
  }

  private userHasOtherConnection(channel: string, userId: string, exceptSocketId: string): boolean {
    for (const ws of this.state.getWebSockets()) {
      const conn = this.attachmentOf(ws);
      if (!conn || conn.socketId === exceptSocketId) {
        continue;
      }
      const member = conn.channels[channel];
      if (member && member.user_id === userId) {
        return true;
      }
    }
    return false;
  }

  private dropConnection(ws: WebSocket): void {
    const conn = this.attachmentOf(ws);
    if (!conn) {
      return;
    }
    for (const [channel, member] of Object.entries(conn.channels)) {
      if (member && isPresenceChannel(channel) && !this.userHasOtherConnection(channel, member.user_id, conn.socketId)) {
        this.broadcast(
          channel,
          encode('pusher_internal:member_removed', channel, { user_id: member.user_id }),
          conn.socketId,
        );
      }
    }
  }

  private attachmentOf(ws: WebSocket): ConnState | null {
    try {
      return ws.deserializeAttachment() as ConnState | null;
    } catch {
      return null;
    }
  }

  private objectData(data: unknown): Record<string, unknown> {
    if (typeof data === 'string') {
      try {
        return JSON.parse(data || '{}') as Record<string, unknown>;
      } catch {
        return {};
      }
    }
    return (data as Record<string, unknown>) ?? {};
  }
}
