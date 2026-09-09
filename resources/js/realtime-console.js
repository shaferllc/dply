/**
 * The Realtime playground client.
 *
 * Speaks enough of the Pusher wire protocol to prove an app works: connect,
 * complete the handshake, subscribe to a public channel, and log every frame.
 * Deliberately NOT pusher-js — the point of this console is to show the relay
 * answering, and a third-party client in the middle would make a failure
 * ambiguous (theirs, ours, or the library's?). The protocol surface used here
 * is small enough that hand-rolling it is the honest option.
 *
 * Publishing is not done here. A publish must be signed with the app secret,
 * which stays in the control plane, so the "send" button calls a Livewire
 * action and the resulting frame arrives back over this socket.
 */

/** Frames worth keeping in the log; older ones scroll out of memory. */
const MAX_LOG_ENTRIES = 40;

/** Pusher protocol version the relay speaks. */
const PROTOCOL = 7;

export function registerRealtimeConsole(Alpine) {
    Alpine.data('dplyRealtimeConsole', (config = {}) => ({
        host: config.host ?? '',
        appKey: config.appKey ?? '',
        channel: config.channel ?? 'demo-channel',

        socket: null,
        /** 'idle' | 'connecting' | 'connected' | 'subscribed' | 'closed' | 'error' */
        state: 'idle',
        socketId: null,
        error: null,
        log: [],
        received: 0,
        /** Set while the user asked to disconnect, so onclose does not retry. */
        deliberate: false,

        init() {
            // wire:navigate tears the DOM out without unloading the page, so an
            // orphaned socket would keep the connection (and the billed slot)
            // open on a page the user has already left.
            const cleanup = () => this.disconnect();
            document.addEventListener('livewire:navigating', cleanup, { once: true });
            this.$watch('channel', () => {
                if (this.state === 'subscribed') {
                    this.resubscribe();
                }
            });
        },

        get connected() {
            return this.state === 'connected' || this.state === 'subscribed';
        },

        get statusLabel() {
            return {
                idle: 'Not connected',
                connecting: 'Connecting…',
                connected: 'Connected',
                subscribed: 'Listening',
                closed: 'Disconnected',
                error: 'Failed',
            }[this.state] ?? this.state;
        },

        connect() {
            if (this.socket || !this.host || !this.appKey) {
                return;
            }

            this.error = null;
            this.deliberate = false;
            this.state = 'connecting';

            const url = `wss://${this.host}/app/${encodeURIComponent(this.appKey)}`
                + `?protocol=${PROTOCOL}&client=dply-console&version=1.0`;

            try {
                this.socket = new WebSocket(url);
            } catch (e) {
                this.fail(e?.message ?? 'Could not open a WebSocket.');
                return;
            }

            this.socket.onopen = () => {
                this.state = 'connected';
                this.push('open', 'Socket open — waiting for the handshake.');
            };

            this.socket.onmessage = (event) => this.handleFrame(event.data);

            this.socket.onerror = () => {
                // The browser deliberately withholds the reason for a failed
                // WebSocket handshake, so there is nothing more specific to say.
                this.fail('The connection failed. Check the app is active and the key is current.');
            };

            this.socket.onclose = (event) => {
                this.socket = null;
                if (this.state === 'error') {
                    return;
                }
                this.state = 'closed';
                this.push('close', this.deliberate
                    ? 'Disconnected.'
                    : `Relay closed the connection (${event.code}).`);
            };
        },

        disconnect() {
            this.deliberate = true;
            if (this.socket) {
                this.socket.close();
                this.socket = null;
            }
            this.state = 'closed';
            this.socketId = null;
        },

        toggle() {
            this.connected || this.state === 'connecting' ? this.disconnect() : this.connect();
        },

        handleFrame(raw) {
            let frame;
            try {
                frame = JSON.parse(raw);
            } catch {
                this.push('raw', String(raw));
                return;
            }

            // Pusher double-encodes `data` as a JSON string inside the frame.
            let data = frame.data;
            if (typeof data === 'string') {
                try {
                    data = JSON.parse(data);
                } catch {
                    /* leave it as the string it is */
                }
            }

            if (frame.event === 'pusher:connection_established') {
                this.socketId = data?.socket_id ?? null;
                this.push('system', `Handshake complete (socket ${this.socketId}).`);
                this.subscribe();
                return;
            }

            if (frame.event === 'pusher_internal:subscription_succeeded') {
                this.state = 'subscribed';
                this.push('system', `Subscribed to ${frame.channel}.`);
                return;
            }

            if (frame.event === 'pusher:error') {
                this.fail(data?.message ?? 'The relay rejected the connection.');
                return;
            }

            if (frame.event === 'pusher:ping') {
                this.send({ event: 'pusher:pong', data: {} });
                return;
            }

            this.received += 1;
            this.push('event', JSON.stringify(data), frame.event, frame.channel);
        },

        subscribe() {
            this.send({ event: 'pusher:subscribe', data: { channel: this.channel } });
        },

        resubscribe() {
            this.send({ event: 'pusher:unsubscribe', data: { channel: this.channel } });
            this.state = 'connected';
            this.subscribe();
        },

        send(payload) {
            if (this.socket?.readyState === WebSocket.OPEN) {
                this.socket.send(JSON.stringify(payload));
            }
        },

        fail(message) {
            this.state = 'error';
            this.error = message;
            this.push('error', message);
        },

        push(kind, message, event = null, channel = null) {
            this.log.unshift({
                id: `${Date.now()}-${this.log.length}`,
                kind,
                message,
                event,
                channel,
                at: new Date().toLocaleTimeString(),
            });
            if (this.log.length > MAX_LOG_ENTRIES) {
                this.log.length = MAX_LOG_ENTRIES;
            }
        },

        clear() {
            this.log = [];
            this.received = 0;
        },
    }));
}
