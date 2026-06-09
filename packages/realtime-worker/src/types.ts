/// <reference types="@cloudflare/workers-types" />

export interface Env {
  // Per-app credentials, written by dply at provision time. Keyed both by
  // `key:{appKey}` (connect lookup) and `id:{appId}` (publish lookup).
  APPS: KVNamespace;
  // One Durable Object instance per app id — the channel/presence hub.
  APP_HUB: DurableObjectNamespace;
  ENVIRONMENT?: string;
}

/** Shape of the JSON record dply writes into the APPS KV namespace. */
export interface AppRecord {
  id: string;
  key: string;
  secret: string;
  enabled: boolean;
  maxConnections?: number;
}
