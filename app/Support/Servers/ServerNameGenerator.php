<?php

declare(strict_types=1);

namespace App\Support\Servers;

use Illuminate\Support\Str;

final class ServerNameGenerator
{
    /** @var list<string> */
    private const ADJECTIVES = [
        'steady', 'brisk', 'bold', 'calm', 'bright',
        'swift', 'sharp', 'amber', 'silver', 'crisp',
        'serene', 'mighty', 'quiet', 'noble', 'gentle',
        'vivid', 'radiant', 'ancient', 'lively', 'silent',
        'sturdy', 'fierce', 'lucid', 'lofty', 'vast',
        'dawn', 'dusk', 'eager', 'tender', 'keen',
        'royal', 'humble', 'frosty', 'verdant', 'pyro',
        'azure', 'sable', 'speckled', 'golden', 'charcoal',
        'cloudy', 'pearl', 'proud', 'agile', 'courageous',
        'stoic', 'mossy', 'sunny', 'stormy', 'wild',
        'shady', 'opal', 'obsidian', 'chilly', 'mellow',
        'breezy', 'scarlet', 'gentle', 'iron', 'platinum',
        // Nautical-themed adjectives
        'salty', 'windward', 'starboard', 'portside', 'tidal',
        'nautical', 'seaborne', 'barnacled', 'briny', 'buoyant',
        'charted', 'coastal', 'maritime', 'fleet', 'oceanic',
        'swabby', 'sandy', 'admiral', 'abel', 'seafaring',
        'weathered', 'ropey', 'glistening', 'coral', 'sun-bleached',
        'shimmering', 'beaconing', 'sailcloth', 'tempestuous', 'ridgeback',
        'drenched', 'starlit', 'windswept', 'kelpy', 'torrid',
    ];

    /** @var list<string> */
    private const NOUNS = [
        'otter', 'falcon', 'harbor', 'summit', 'spruce',
        'signal', 'meadow', 'comet', 'anchor', 'cinder',
        'ridge', 'pioneer', 'heron', 'forge', 'grove',
        'cedar', 'ember', 'canopy', 'haven', 'delta',
        'crest', 'bison', 'buck', 'ocean', 'peak',
        'brook', 'dawn', 'stone', 'serpent', 'fox',
        'lodge', 'forge', 'thicket', 'lynx', 'pine',
        'echo', 'shadow', 'glade', 'vale', 'den',
        'cliff', 'bluff', 'mesa', 'crag', 'fjord',
        'drift', 'tide', 'blaze', 'tundra', 'grove',
        'reef', 'sky', 'wave', 'blizzard', 'gale',
        'delta', 'finch', 'owl', 'falcon', 'wolf',
        'stag', 'quartz', 'harbor', 'boulder', 'ravine',
        'meadow', 'willow', 'monolith', 'torrent', 'iris',
        // Nautical-type nouns
        'skipper', 'buoy', 'crowsnest', 'galley', 'helm',
        'hull', 'port', 'starboard', 'brig', 'mast',
        'deck', 'hold', 'keel', 'dock', 'pier',
        'harbor', 'lagoon', 'isle', 'current', 'shoal',
        'beacon', 'anchor', 'marina', 'stern', 'prow',
        'galleon', 'frigate', 'corvette', 'lifeboat', 'tiller',
        'mooring', 'rudder', 'lantern', 'albatross', 'gull',
        'seagull', 'chart', 'ocean', 'voyage', 'navigator',
        'harpoon', 'cutter', 'buccaneer', 'pilot', 'bosun',
        'mate', 'sailor', 'cabin', 'dory', 'turtle',
        'dolphin', 'orca', 'leviathan', 'reef', 'whale',
        'kraken', 'mermaid', 'fluke', 'harbor', 'current',
        'gale', 'schooner', 'trawler', 'jetty', 'wharf',
        'foghorn', 'leadline', 'map', 'chronometer', 'drifter',
        'wave', 'sirena', 'neptune', 'draught', 'spray',
    ];

    public static function generate(): string
    {
        $adj = self::ADJECTIVES[array_rand(self::ADJECTIVES)];
        $noun = self::NOUNS[array_rand(self::NOUNS)];

        return Str::slug($adj.'-'.$noun);
    }
}
