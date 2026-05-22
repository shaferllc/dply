<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Curated reference catalog for the workspace REPL — the commands an operator is most
 * likely to run by hand against a redis-family engine (Redis / Valkey / KeyDB / Dragonfly,
 * which all speak RESP and share the same command surface for the things you'd actually
 * type at a console).
 *
 * Not exhaustive — Redis ships ~250 commands across pubsub/streams/cluster/scripting/etc.
 * The aim is "what would you reach for at 2am to inspect or poke at a running cache",
 * not API completeness. New entries should pull their weight: pick verbs that operators
 * actually run, not full API surface.
 *
 * The catalog is plain data — no engine probes, no SSH. Used by the REPL UI for autocomplete
 * and the command-reference modal. Keep `mutating` honest because we surface it as a badge
 * in the reference modal so operators know which need the unlock.
 */
class CacheCommandCatalog
{
    public const GROUP_STRINGS = 'Strings';

    public const GROUP_HASHES = 'Hashes';

    public const GROUP_LISTS = 'Lists';

    public const GROUP_SETS = 'Sets';

    public const GROUP_ZSETS = 'Sorted sets';

    public const GROUP_KEYS = 'Keys & expiration';

    public const GROUP_PUBSUB = 'Pub/sub';

    public const GROUP_STREAMS = 'Streams';

    public const GROUP_SERVER = 'Server & info';

    public const GROUP_CLIENT = 'Client & connection';

    public const GROUP_SCRIPTING = 'Scripting';

    public const GROUP_CLUSTER = 'Cluster & replication';

    /**
     * RESP-family command catalog. The same list is used for Redis, Valkey, KeyDB, and
     * Dragonfly — wire-compatible engines that all accept the same verbs (Dragonfly skips
     * a few cluster-only ones, but the operator will get a clean error if they hit one).
     *
     * @return list<array{name: string, syntax: string, summary: string, group: string, mutating: bool}>
     */
    public static function respFamily(): array
    {
        return [
            // Strings
            ['name' => 'GET', 'syntax' => 'GET key', 'summary' => 'Get the string value at key.', 'group' => self::GROUP_STRINGS, 'mutating' => false],
            ['name' => 'SET', 'syntax' => 'SET key value [EX seconds] [PX ms] [NX|XX]', 'summary' => 'Set the string value of a key, optionally with TTL or only-if-(not-)exists.', 'group' => self::GROUP_STRINGS, 'mutating' => true],
            ['name' => 'SETNX', 'syntax' => 'SETNX key value', 'summary' => 'Set the value only if the key does not already exist.', 'group' => self::GROUP_STRINGS, 'mutating' => true],
            ['name' => 'GETSET', 'syntax' => 'GETSET key value', 'summary' => 'Atomically set a key and return its old value.', 'group' => self::GROUP_STRINGS, 'mutating' => true],
            ['name' => 'MGET', 'syntax' => 'MGET key [key ...]', 'summary' => 'Get values of multiple keys in one round-trip.', 'group' => self::GROUP_STRINGS, 'mutating' => false],
            ['name' => 'MSET', 'syntax' => 'MSET key value [key value ...]', 'summary' => 'Set multiple keys to values in one round-trip.', 'group' => self::GROUP_STRINGS, 'mutating' => true],
            ['name' => 'APPEND', 'syntax' => 'APPEND key value', 'summary' => 'Append to the existing string value.', 'group' => self::GROUP_STRINGS, 'mutating' => true],
            ['name' => 'INCR', 'syntax' => 'INCR key', 'summary' => 'Increment the integer value of a key by 1.', 'group' => self::GROUP_STRINGS, 'mutating' => true],
            ['name' => 'DECR', 'syntax' => 'DECR key', 'summary' => 'Decrement the integer value of a key by 1.', 'group' => self::GROUP_STRINGS, 'mutating' => true],
            ['name' => 'INCRBY', 'syntax' => 'INCRBY key increment', 'summary' => 'Increment the integer value of a key by N.', 'group' => self::GROUP_STRINGS, 'mutating' => true],
            ['name' => 'DECRBY', 'syntax' => 'DECRBY key decrement', 'summary' => 'Decrement the integer value of a key by N.', 'group' => self::GROUP_STRINGS, 'mutating' => true],
            ['name' => 'STRLEN', 'syntax' => 'STRLEN key', 'summary' => 'Length of the string value stored at a key.', 'group' => self::GROUP_STRINGS, 'mutating' => false],
            ['name' => 'GETRANGE', 'syntax' => 'GETRANGE key start end', 'summary' => 'Substring of the value, inclusive byte offsets.', 'group' => self::GROUP_STRINGS, 'mutating' => false],

            // Hashes
            ['name' => 'HGET', 'syntax' => 'HGET key field', 'summary' => 'Get one field of a hash.', 'group' => self::GROUP_HASHES, 'mutating' => false],
            ['name' => 'HSET', 'syntax' => 'HSET key field value [field value ...]', 'summary' => 'Set one or more fields on a hash.', 'group' => self::GROUP_HASHES, 'mutating' => true],
            ['name' => 'HMGET', 'syntax' => 'HMGET key field [field ...]', 'summary' => 'Get several fields of a hash.', 'group' => self::GROUP_HASHES, 'mutating' => false],
            ['name' => 'HGETALL', 'syntax' => 'HGETALL key', 'summary' => 'Get all field/value pairs of a hash.', 'group' => self::GROUP_HASHES, 'mutating' => false],
            ['name' => 'HDEL', 'syntax' => 'HDEL key field [field ...]', 'summary' => 'Delete one or more fields from a hash.', 'group' => self::GROUP_HASHES, 'mutating' => true],
            ['name' => 'HKEYS', 'syntax' => 'HKEYS key', 'summary' => 'List the field names in a hash.', 'group' => self::GROUP_HASHES, 'mutating' => false],
            ['name' => 'HVALS', 'syntax' => 'HVALS key', 'summary' => 'List the values in a hash.', 'group' => self::GROUP_HASHES, 'mutating' => false],
            ['name' => 'HLEN', 'syntax' => 'HLEN key', 'summary' => 'Number of fields in a hash.', 'group' => self::GROUP_HASHES, 'mutating' => false],
            ['name' => 'HEXISTS', 'syntax' => 'HEXISTS key field', 'summary' => 'Check whether a field exists on a hash.', 'group' => self::GROUP_HASHES, 'mutating' => false],
            ['name' => 'HINCRBY', 'syntax' => 'HINCRBY key field increment', 'summary' => 'Increment an integer field on a hash.', 'group' => self::GROUP_HASHES, 'mutating' => true],
            ['name' => 'HSCAN', 'syntax' => 'HSCAN key cursor [MATCH pattern] [COUNT n]', 'summary' => 'Iterate fields of a hash without blocking.', 'group' => self::GROUP_HASHES, 'mutating' => false],

            // Lists
            ['name' => 'LPUSH', 'syntax' => 'LPUSH key value [value ...]', 'summary' => 'Prepend one or more values to a list.', 'group' => self::GROUP_LISTS, 'mutating' => true],
            ['name' => 'RPUSH', 'syntax' => 'RPUSH key value [value ...]', 'summary' => 'Append one or more values to a list.', 'group' => self::GROUP_LISTS, 'mutating' => true],
            ['name' => 'LPOP', 'syntax' => 'LPOP key [count]', 'summary' => 'Remove and return one or more values from the head.', 'group' => self::GROUP_LISTS, 'mutating' => true],
            ['name' => 'RPOP', 'syntax' => 'RPOP key [count]', 'summary' => 'Remove and return one or more values from the tail.', 'group' => self::GROUP_LISTS, 'mutating' => true],
            ['name' => 'LRANGE', 'syntax' => 'LRANGE key start stop', 'summary' => 'Slice a list by inclusive index (negative indexes count from end).', 'group' => self::GROUP_LISTS, 'mutating' => false],
            ['name' => 'LLEN', 'syntax' => 'LLEN key', 'summary' => 'Length of a list.', 'group' => self::GROUP_LISTS, 'mutating' => false],
            ['name' => 'LINDEX', 'syntax' => 'LINDEX key index', 'summary' => 'Element at the given index of a list.', 'group' => self::GROUP_LISTS, 'mutating' => false],
            ['name' => 'LSET', 'syntax' => 'LSET key index value', 'summary' => 'Set the element at index in a list.', 'group' => self::GROUP_LISTS, 'mutating' => true],
            ['name' => 'LREM', 'syntax' => 'LREM key count value', 'summary' => 'Remove count occurrences of value from a list.', 'group' => self::GROUP_LISTS, 'mutating' => true],
            ['name' => 'LTRIM', 'syntax' => 'LTRIM key start stop', 'summary' => 'Trim a list to the given range, inclusive.', 'group' => self::GROUP_LISTS, 'mutating' => true],

            // Sets
            ['name' => 'SADD', 'syntax' => 'SADD key member [member ...]', 'summary' => 'Add one or more members to a set.', 'group' => self::GROUP_SETS, 'mutating' => true],
            ['name' => 'SREM', 'syntax' => 'SREM key member [member ...]', 'summary' => 'Remove members from a set.', 'group' => self::GROUP_SETS, 'mutating' => true],
            ['name' => 'SMEMBERS', 'syntax' => 'SMEMBERS key', 'summary' => 'All members of a set.', 'group' => self::GROUP_SETS, 'mutating' => false],
            ['name' => 'SCARD', 'syntax' => 'SCARD key', 'summary' => 'Cardinality (member count) of a set.', 'group' => self::GROUP_SETS, 'mutating' => false],
            ['name' => 'SISMEMBER', 'syntax' => 'SISMEMBER key member', 'summary' => 'Check whether a value is a member of a set.', 'group' => self::GROUP_SETS, 'mutating' => false],
            ['name' => 'SPOP', 'syntax' => 'SPOP key [count]', 'summary' => 'Remove and return random member(s) from a set.', 'group' => self::GROUP_SETS, 'mutating' => true],
            ['name' => 'SINTER', 'syntax' => 'SINTER key [key ...]', 'summary' => 'Intersection of multiple sets.', 'group' => self::GROUP_SETS, 'mutating' => false],
            ['name' => 'SUNION', 'syntax' => 'SUNION key [key ...]', 'summary' => 'Union of multiple sets.', 'group' => self::GROUP_SETS, 'mutating' => false],
            ['name' => 'SDIFF', 'syntax' => 'SDIFF key [key ...]', 'summary' => 'Difference of the first set vs the others.', 'group' => self::GROUP_SETS, 'mutating' => false],

            // Sorted sets
            ['name' => 'ZADD', 'syntax' => 'ZADD key [NX|XX] [GT|LT] score member [score member ...]', 'summary' => 'Add or update members of a sorted set.', 'group' => self::GROUP_ZSETS, 'mutating' => true],
            ['name' => 'ZRANGE', 'syntax' => 'ZRANGE key start stop [WITHSCORES]', 'summary' => 'Range of members in ascending score/lex order.', 'group' => self::GROUP_ZSETS, 'mutating' => false],
            ['name' => 'ZREVRANGE', 'syntax' => 'ZREVRANGE key start stop [WITHSCORES]', 'summary' => 'Range of members in descending order.', 'group' => self::GROUP_ZSETS, 'mutating' => false],
            ['name' => 'ZRANGEBYSCORE', 'syntax' => 'ZRANGEBYSCORE key min max [WITHSCORES] [LIMIT offset count]', 'summary' => 'Members within a score range.', 'group' => self::GROUP_ZSETS, 'mutating' => false],
            ['name' => 'ZRANK', 'syntax' => 'ZRANK key member', 'summary' => 'Index (rank) of a member, ascending order.', 'group' => self::GROUP_ZSETS, 'mutating' => false],
            ['name' => 'ZSCORE', 'syntax' => 'ZSCORE key member', 'summary' => 'Score of a member.', 'group' => self::GROUP_ZSETS, 'mutating' => false],
            ['name' => 'ZCARD', 'syntax' => 'ZCARD key', 'summary' => 'Cardinality of a sorted set.', 'group' => self::GROUP_ZSETS, 'mutating' => false],
            ['name' => 'ZINCRBY', 'syntax' => 'ZINCRBY key increment member', 'summary' => 'Increment the score of a member.', 'group' => self::GROUP_ZSETS, 'mutating' => true],
            ['name' => 'ZREM', 'syntax' => 'ZREM key member [member ...]', 'summary' => 'Remove members from a sorted set.', 'group' => self::GROUP_ZSETS, 'mutating' => true],
            ['name' => 'ZPOPMIN', 'syntax' => 'ZPOPMIN key [count]', 'summary' => 'Remove and return lowest-score member(s).', 'group' => self::GROUP_ZSETS, 'mutating' => true],
            ['name' => 'ZPOPMAX', 'syntax' => 'ZPOPMAX key [count]', 'summary' => 'Remove and return highest-score member(s).', 'group' => self::GROUP_ZSETS, 'mutating' => true],

            // Keys / general
            ['name' => 'KEYS', 'syntax' => 'KEYS pattern', 'summary' => 'List keys matching a glob pattern. Avoid on large keyspaces — use SCAN.', 'group' => self::GROUP_KEYS, 'mutating' => false],
            ['name' => 'SCAN', 'syntax' => 'SCAN cursor [MATCH pattern] [COUNT n] [TYPE t]', 'summary' => 'Iterate keys without blocking the server.', 'group' => self::GROUP_KEYS, 'mutating' => false],
            ['name' => 'EXISTS', 'syntax' => 'EXISTS key [key ...]', 'summary' => 'Number of given keys that exist.', 'group' => self::GROUP_KEYS, 'mutating' => false],
            ['name' => 'TYPE', 'syntax' => 'TYPE key', 'summary' => 'Type of the value stored at a key.', 'group' => self::GROUP_KEYS, 'mutating' => false],
            ['name' => 'TTL', 'syntax' => 'TTL key', 'summary' => 'Remaining TTL in seconds. -1 = no TTL, -2 = missing.', 'group' => self::GROUP_KEYS, 'mutating' => false],
            ['name' => 'PTTL', 'syntax' => 'PTTL key', 'summary' => 'Remaining TTL in milliseconds.', 'group' => self::GROUP_KEYS, 'mutating' => false],
            ['name' => 'EXPIRE', 'syntax' => 'EXPIRE key seconds [NX|XX|GT|LT]', 'summary' => 'Set a TTL on a key.', 'group' => self::GROUP_KEYS, 'mutating' => true],
            ['name' => 'PEXPIRE', 'syntax' => 'PEXPIRE key milliseconds', 'summary' => 'Set a TTL on a key in milliseconds.', 'group' => self::GROUP_KEYS, 'mutating' => true],
            ['name' => 'PERSIST', 'syntax' => 'PERSIST key', 'summary' => 'Remove the TTL from a key.', 'group' => self::GROUP_KEYS, 'mutating' => true],
            ['name' => 'DEL', 'syntax' => 'DEL key [key ...]', 'summary' => 'Delete one or more keys.', 'group' => self::GROUP_KEYS, 'mutating' => true],
            ['name' => 'UNLINK', 'syntax' => 'UNLINK key [key ...]', 'summary' => 'Asynchronous DEL — frees memory in the background.', 'group' => self::GROUP_KEYS, 'mutating' => true],
            ['name' => 'RENAME', 'syntax' => 'RENAME key newkey', 'summary' => 'Rename a key, overwriting any existing newkey.', 'group' => self::GROUP_KEYS, 'mutating' => true],
            ['name' => 'RENAMENX', 'syntax' => 'RENAMENX key newkey', 'summary' => 'Rename a key only if newkey does not exist.', 'group' => self::GROUP_KEYS, 'mutating' => true],
            ['name' => 'RANDOMKEY', 'syntax' => 'RANDOMKEY', 'summary' => 'A random key from the current database.', 'group' => self::GROUP_KEYS, 'mutating' => false],
            ['name' => 'DUMP', 'syntax' => 'DUMP key', 'summary' => 'Serialized binary value at a key (for RESTORE).', 'group' => self::GROUP_KEYS, 'mutating' => false],
            ['name' => 'OBJECT ENCODING', 'syntax' => 'OBJECT ENCODING key', 'summary' => 'Internal encoding (ziplist, hashtable, intset, …) of a key.', 'group' => self::GROUP_KEYS, 'mutating' => false],
            ['name' => 'OBJECT IDLETIME', 'syntax' => 'OBJECT IDLETIME key', 'summary' => 'Seconds since the key was last touched.', 'group' => self::GROUP_KEYS, 'mutating' => false],
            ['name' => 'MEMORY USAGE', 'syntax' => 'MEMORY USAGE key [SAMPLES n]', 'summary' => 'Approximate memory cost of a key.', 'group' => self::GROUP_KEYS, 'mutating' => false],

            // Streams
            ['name' => 'XADD', 'syntax' => 'XADD key [MAXLEN [~|=] count] *|id field value [field value ...]', 'summary' => 'Append an entry to a stream.', 'group' => self::GROUP_STREAMS, 'mutating' => true],
            ['name' => 'XLEN', 'syntax' => 'XLEN key', 'summary' => 'Number of entries in a stream.', 'group' => self::GROUP_STREAMS, 'mutating' => false],
            ['name' => 'XRANGE', 'syntax' => 'XRANGE key start end [COUNT n]', 'summary' => 'Range of entries between two IDs.', 'group' => self::GROUP_STREAMS, 'mutating' => false],
            ['name' => 'XREVRANGE', 'syntax' => 'XREVRANGE key end start [COUNT n]', 'summary' => 'Range of entries in reverse order.', 'group' => self::GROUP_STREAMS, 'mutating' => false],
            ['name' => 'XREAD', 'syntax' => 'XREAD [COUNT n] [BLOCK ms] STREAMS key [key ...] id [id ...]', 'summary' => 'Read entries from one or more streams.', 'group' => self::GROUP_STREAMS, 'mutating' => false],
            ['name' => 'XINFO STREAM', 'syntax' => 'XINFO STREAM key', 'summary' => 'Metadata for a stream — first/last id, length, groups.', 'group' => self::GROUP_STREAMS, 'mutating' => false],
            ['name' => 'XINFO GROUPS', 'syntax' => 'XINFO GROUPS key', 'summary' => 'Consumer group metadata for a stream.', 'group' => self::GROUP_STREAMS, 'mutating' => false],
            ['name' => 'XPENDING', 'syntax' => 'XPENDING key group', 'summary' => 'Pending entries for a consumer group.', 'group' => self::GROUP_STREAMS, 'mutating' => false],

            // Pub/sub
            ['name' => 'PUBLISH', 'syntax' => 'PUBLISH channel message', 'summary' => 'Publish a message to a channel.', 'group' => self::GROUP_PUBSUB, 'mutating' => true],
            ['name' => 'PUBSUB CHANNELS', 'syntax' => 'PUBSUB CHANNELS [pattern]', 'summary' => 'Active channels matching pattern.', 'group' => self::GROUP_PUBSUB, 'mutating' => false],
            ['name' => 'PUBSUB NUMSUB', 'syntax' => 'PUBSUB NUMSUB [channel ...]', 'summary' => 'Subscriber counts for channels.', 'group' => self::GROUP_PUBSUB, 'mutating' => false],

            // Server / info
            ['name' => 'PING', 'syntax' => 'PING [message]', 'summary' => 'Round-trip ping. Returns PONG (or echoes the message).', 'group' => self::GROUP_SERVER, 'mutating' => false],
            ['name' => 'INFO', 'syntax' => 'INFO [section]', 'summary' => 'Server stats. Sections: server, clients, memory, persistence, stats, replication, cpu, keyspace.', 'group' => self::GROUP_SERVER, 'mutating' => false],
            ['name' => 'DBSIZE', 'syntax' => 'DBSIZE', 'summary' => 'Number of keys in the current database.', 'group' => self::GROUP_SERVER, 'mutating' => false],
            ['name' => 'TIME', 'syntax' => 'TIME', 'summary' => 'Server time as (seconds, microseconds).', 'group' => self::GROUP_SERVER, 'mutating' => false],
            ['name' => 'ROLE', 'syntax' => 'ROLE', 'summary' => 'Role (master/slave/sentinel) and replication info.', 'group' => self::GROUP_SERVER, 'mutating' => false],
            ['name' => 'CONFIG GET', 'syntax' => 'CONFIG GET parameter', 'summary' => 'Read a server config directive (supports glob).', 'group' => self::GROUP_SERVER, 'mutating' => false],
            ['name' => 'CONFIG SET', 'syntax' => 'CONFIG SET parameter value', 'summary' => 'Update a server config directive at runtime.', 'group' => self::GROUP_SERVER, 'mutating' => true],
            ['name' => 'CONFIG RESETSTAT', 'syntax' => 'CONFIG RESETSTAT', 'summary' => 'Reset the server stats counters.', 'group' => self::GROUP_SERVER, 'mutating' => true],
            ['name' => 'SLOWLOG GET', 'syntax' => 'SLOWLOG GET [n]', 'summary' => 'Most recent slow-query entries.', 'group' => self::GROUP_SERVER, 'mutating' => false],
            ['name' => 'SLOWLOG LEN', 'syntax' => 'SLOWLOG LEN', 'summary' => 'Slow log length.', 'group' => self::GROUP_SERVER, 'mutating' => false],
            ['name' => 'LATENCY LATEST', 'syntax' => 'LATENCY LATEST', 'summary' => 'Latest latency events per category.', 'group' => self::GROUP_SERVER, 'mutating' => false],
            ['name' => 'MEMORY STATS', 'syntax' => 'MEMORY STATS', 'summary' => 'Detailed memory allocator statistics.', 'group' => self::GROUP_SERVER, 'mutating' => false],
            ['name' => 'FLUSHDB', 'syntax' => 'FLUSHDB [ASYNC|SYNC]', 'summary' => 'Drop all keys in the current database.', 'group' => self::GROUP_SERVER, 'mutating' => true],
            ['name' => 'FLUSHALL', 'syntax' => 'FLUSHALL [ASYNC|SYNC]', 'summary' => 'Drop all keys in every database.', 'group' => self::GROUP_SERVER, 'mutating' => true],
            ['name' => 'DEBUG OBJECT', 'syntax' => 'DEBUG OBJECT key', 'summary' => 'Internal-debug info for a key (encoding, refcount, …).', 'group' => self::GROUP_SERVER, 'mutating' => false],

            // Client / connection
            ['name' => 'CLIENT LIST', 'syntax' => 'CLIENT LIST [TYPE normal|master|replica|pubsub]', 'summary' => 'Connected clients with addresses, names, idle, db.', 'group' => self::GROUP_CLIENT, 'mutating' => false],
            ['name' => 'CLIENT ID', 'syntax' => 'CLIENT ID', 'summary' => 'The current connection\'s client id.', 'group' => self::GROUP_CLIENT, 'mutating' => false],
            ['name' => 'CLIENT GETNAME', 'syntax' => 'CLIENT GETNAME', 'summary' => 'Name on the current connection (if any).', 'group' => self::GROUP_CLIENT, 'mutating' => false],
            ['name' => 'CLIENT SETNAME', 'syntax' => 'CLIENT SETNAME name', 'summary' => 'Tag the current connection with a name.', 'group' => self::GROUP_CLIENT, 'mutating' => true],
            ['name' => 'CLIENT KILL', 'syntax' => 'CLIENT KILL ID id|ADDR ip:port', 'summary' => 'Disconnect a client by id or address.', 'group' => self::GROUP_CLIENT, 'mutating' => true],
            ['name' => 'SELECT', 'syntax' => 'SELECT db', 'summary' => 'Switch to logical database number.', 'group' => self::GROUP_CLIENT, 'mutating' => false],
            ['name' => 'AUTH', 'syntax' => 'AUTH [username] password', 'summary' => 'Authenticate the current connection.', 'group' => self::GROUP_CLIENT, 'mutating' => false],
            ['name' => 'ECHO', 'syntax' => 'ECHO message', 'summary' => 'Echoes the given string back.', 'group' => self::GROUP_CLIENT, 'mutating' => false],

            // Scripting
            ['name' => 'EVAL', 'syntax' => 'EVAL script numkeys key [key ...] arg [arg ...]', 'summary' => 'Run a Lua script.', 'group' => self::GROUP_SCRIPTING, 'mutating' => true],
            ['name' => 'EVALSHA', 'syntax' => 'EVALSHA sha1 numkeys key [key ...] arg [arg ...]', 'summary' => 'Run a previously-loaded Lua script by SHA1.', 'group' => self::GROUP_SCRIPTING, 'mutating' => true],
            ['name' => 'SCRIPT LOAD', 'syntax' => 'SCRIPT LOAD "script"', 'summary' => 'Load a Lua script and return its SHA1.', 'group' => self::GROUP_SCRIPTING, 'mutating' => true],
            ['name' => 'SCRIPT EXISTS', 'syntax' => 'SCRIPT EXISTS sha1 [sha1 ...]', 'summary' => 'Check whether scripts are cached.', 'group' => self::GROUP_SCRIPTING, 'mutating' => false],
            ['name' => 'SCRIPT FLUSH', 'syntax' => 'SCRIPT FLUSH [ASYNC|SYNC]', 'summary' => 'Clear the script cache.', 'group' => self::GROUP_SCRIPTING, 'mutating' => true],

            // Cluster / replication
            ['name' => 'CLUSTER INFO', 'syntax' => 'CLUSTER INFO', 'summary' => 'Cluster state, slots assigned, known nodes.', 'group' => self::GROUP_CLUSTER, 'mutating' => false],
            ['name' => 'CLUSTER NODES', 'syntax' => 'CLUSTER NODES', 'summary' => 'Detailed view of every cluster node.', 'group' => self::GROUP_CLUSTER, 'mutating' => false],
            ['name' => 'CLUSTER SLOTS', 'syntax' => 'CLUSTER SLOTS', 'summary' => 'Slot ranges and the nodes responsible for them.', 'group' => self::GROUP_CLUSTER, 'mutating' => false],
            ['name' => 'CLUSTER MYID', 'syntax' => 'CLUSTER MYID', 'summary' => 'This node\'s id in the cluster.', 'group' => self::GROUP_CLUSTER, 'mutating' => false],
            ['name' => 'LASTSAVE', 'syntax' => 'LASTSAVE', 'summary' => 'UNIX timestamp of the last successful disk save.', 'group' => self::GROUP_CLUSTER, 'mutating' => false],
        ];
    }

    /**
     * Same data, grouped for the modal's category sections.
     *
     * @return array<string, list<array{name: string, syntax: string, summary: string, group: string, mutating: bool}>>
     */
    public static function respFamilyByGroup(): array
    {
        $grouped = [];
        foreach (self::respFamily() as $cmd) {
            $grouped[$cmd['group']][] = $cmd;
        }

        return $grouped;
    }
}
