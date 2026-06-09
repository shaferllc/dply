// Pusher wire-protocol helpers. The realtime Worker speaks the same protocol
// as Pusher Channels / Laravel Reverb, so `laravel-echo` + `pusher-js` connect
// to it unchanged. Every outbound frame carries `data` as a JSON *string*
// (Pusher double-encodes the payload); clients JSON.parse it on receipt.

export interface InboundMessage {
  event: string;
  channel?: string;
  data?: unknown;
}

/** Build an outbound Pusher frame with the conventional double-encoded data. */
export function encode(event: string, channel: string | undefined, data: unknown): string {
  const frame: Record<string, unknown> = { event };
  if (channel !== undefined) {
    frame.channel = channel;
  }
  frame.data = typeof data === 'string' ? data : JSON.stringify(data ?? {});
  return JSON.stringify(frame);
}

export function isPrivateChannel(channel: string): boolean {
  return channel.startsWith('private-');
}

export function isPresenceChannel(channel: string): boolean {
  return channel.startsWith('presence-');
}

/** Private + presence channels require an HMAC auth token to subscribe. */
export function channelRequiresAuth(channel: string): boolean {
  return isPrivateChannel(channel) || isPresenceChannel(channel);
}

export function isClientEvent(event: string): boolean {
  return event.startsWith('client-');
}

/** Pusher socket ids are two dot-separated integers, e.g. "123456.789012". */
export function makeSocketId(rand: () => number = Math.random): string {
  const left = Math.floor(rand() * 1_000_000_000);
  const right = Math.floor(rand() * 1_000_000_000);
  return `${left}.${right}`;
}

export interface PresenceMember {
  user_id: string;
  user_info?: unknown;
}

/** Pusher presence payload: { count, ids, hash } keyed by user_id. */
export function buildPresencePayload(members: Map<string, unknown>): {
  presence: { count: number; ids: string[]; hash: Record<string, unknown> };
} {
  return {
    presence: {
      count: members.size,
      ids: [...members.keys()],
      hash: Object.fromEntries(members),
    },
  };
}
