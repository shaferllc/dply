<?php

declare(strict_types=1);

namespace App\Support\Servers;

use Illuminate\Support\Str;

final class ServerNameGenerator
{
    /** @var list<string> */
    private const ADJECTIVES = [
        // Mood & character
        'steady', 'brisk', 'bold', 'calm', 'bright',
        'swift', 'sharp', 'serene', 'mighty', 'quiet',
        'noble', 'gentle', 'vivid', 'radiant', 'ancient',
        'lively', 'silent', 'sturdy', 'fierce', 'lucid',
        'lofty', 'vast', 'eager', 'tender', 'keen',
        'royal', 'humble', 'proud', 'agile', 'courageous',
        'stoic', 'wild', 'mellow', 'iron', 'platinum',
        'hidden', 'secret', 'rare', 'regal', 'youthful',
        'somber', 'soundless', 'sudden', 'still', 'warm',
        // Color & light
        'amber', 'silver', 'golden', 'charcoal', 'scarlet',
        'azure', 'sable', 'opal', 'obsidian', 'pearl',
        'crimson', 'cobalt', 'copper', 'bronze', 'ivory',
        'indigo', 'jade', 'jet', 'lilac', 'lemon',
        'teal', 'topaz', 'ruby', 'russet', 'sage',
        'violet', 'wheaten', 'yellow', 'orange', 'purple',
        'emerald', 'marble', 'slate', 'neon', 'midnight',
        'sunset', 'sunlit', 'starlit', 'moonlit', 'dappled',
        'prismatic', 'iridescent', 'luminous', 'glowing', 'gleaming',
        // Weather & sky
        'frosty', 'cloudy', 'sunny', 'stormy', 'chilly',
        'breezy', 'misty', 'foggy', 'rainy', 'snowy',
        'windy', 'hazy', 'drizzly', 'thunder', 'blazing',
        'dawn', 'dusk', 'twilight', 'lunar', 'solar',
        'celestial', 'auroral', 'overcast', 'dewy', 'icy',
        'wintry', 'torrid', 'humid', 'arid', 'tropical',
        // Texture & material
        'crisp', 'mossy', 'shady', 'speckled', 'verdant',
        'pyro', 'burnished', 'polished', 'rough', 'smooth',
        'gravelly', 'sandy', 'rocky', 'stony', 'woody',
        'woolen', 'silken', 'satin', 'velvet', 'waxy',
        'porous', 'knotted', 'woven', 'carved', 'layered',
        'crystalline', 'mineral', 'metallic', 'ceramic', 'glassy',
        'papery', 'feathery', 'fuzzy', 'fluffy', 'spiny',
        'thorny', 'bristly', 'scaly', 'ridged', 'grooved',
        // Nature & landscape
        'verdant', 'leafy', 'ferny', 'piney', 'grassy',
        'marshy', 'swampy', 'forested', 'wooded', 'jungle',
        'alpine', 'arctic', 'boreal', 'coastal', 'highland',
        'mountain', 'rolling', 'winding', 'narrow', 'wide',
        'steep', 'sprawling', 'towering', 'hollow', 'dense',
        'sparse', 'overgrown', 'brambled', 'reedy', 'shrubby',
        'pastoral', 'fragrant', 'floral', 'evergreen', 'deciduous',
        'glacial', 'volcanic', 'geologic', 'fossil', 'mineral',
        // Motion & energy
        'drifting', 'rippling', 'streaming', 'pulsing', 'flickering',
        'whispering', 'rumbling', 'surging', 'cascading', 'spiraling',
        'wandering', 'roaming', 'soaring', 'darting', 'leaping',
        'crawling', 'trotting', 'galloping', 'pouncing', 'gliding',
        // Nautical
        'salty', 'windward', 'starboard', 'portside', 'tidal',
        'nautical', 'seaborne', 'barnacled', 'briny', 'buoyant',
        'charted', 'maritime', 'fleet', 'oceanic', 'seafaring',
        'weathered', 'ropey', 'glistening', 'coral', 'sun-bleached',
        'shimmering', 'tempestuous', 'drenched', 'windswept', 'kelpy',
        'submerged', 'anchored', 'moored', 'sailcloth', 'rigging',
        // Animal-inspired
        'striped', 'spotted', 'dappled', 'maned', 'horned',
        'tusked', 'clawed', 'winged', 'scaled', 'furred',
        'feathered', 'webbed', 'antlered', 'beaked', 'taloned',
        'nocturnal', 'diurnal', 'migratory', 'nesting', 'burrowing',
        'predatory', 'foraging', 'pack', 'herding', 'solitary',
    ];

    /** @var list<string> */
    private const NOUNS = [
        // Mammals
        'otter', 'bison', 'buck', 'fox', 'lynx',
        'wolf', 'stag', 'badger', 'bear', 'beaver',
        'bobcat', 'buffalo', 'camel', 'caribou', 'cheetah',
        'chipmunk', 'cougar', 'coyote', 'deer', 'elk',
        'giraffe', 'goat', 'gorilla', 'hedgehog', 'hippo',
        'horse', 'hyena', 'ibex', 'jackal', 'jaguar',
        'kangaroo', 'koala', 'leopard', 'lion', 'llama',
        'manatee', 'marmot', 'mole', 'moose', 'mouse',
        'muskox', 'opossum', 'panda', 'panther', 'platypus',
        'porcupine', 'puma', 'rabbit', 'raccoon', 'ram',
        'rhino', 'seal', 'sheep', 'skunk', 'sloth',
        'squirrel', 'tapir', 'tiger', 'walrus', 'warthog',
        'weasel', 'wolverine', 'yak', 'zebra', 'antelope',
        'armadillo', 'baboon', 'bat', 'bison', 'boar',
        // Birds
        'falcon', 'heron', 'finch', 'owl', 'eagle',
        'hawk', 'raven', 'crow', 'robin', 'sparrow',
        'swan', 'crane', 'pelican', 'osprey', 'condor',
        'vulture', 'parrot', 'macaw', 'toucan', 'penguin',
        'puffin', 'albatross', 'gull', 'tern', 'petrel',
        'kingfisher', 'woodpecker', 'wren', 'thrush', 'warbler',
        'cardinal', 'bluebird', 'magpie', 'starling', 'oriole',
        'quail', 'partridge', 'pheasant', 'grouse', 'duck',
        'goose', 'heron', 'ibis', 'flamingo', 'peacock',
        'phoenix', 'merlin', 'kite', 'loon', 'nuthatch',
        // Insects & small creatures
        'ant', 'bee', 'beetle', 'butterfly', 'dragonfly',
        'firefly', 'grasshopper', 'cricket', 'moth', 'wasp',
        'hornet', 'spider', 'scorpion', 'mantis', 'cicada',
        'ladybug', 'snail', 'slug', 'worm', 'centipede',
        // Reptiles & amphibians
        'serpent', 'python', 'cobra', 'viper', 'gecko',
        'iguana', 'lizard', 'chameleon', 'tortoise', 'turtle',
        'alligator', 'crocodile', 'frog', 'toad', 'salamander',
        'newt', 'chameleon', 'monitor', 'skink', 'anaconda',
        // Marine life
        'dolphin', 'orca', 'whale', 'shark', 'ray',
        'octopus', 'squid', 'jellyfish', 'starfish', 'seahorse',
        'lobster', 'crab', 'clam', 'oyster', 'salmon',
        'trout', 'bass', 'tuna', 'swordfish', 'marlin',
        'narwhal', 'manatee', 'walrus', 'seal', 'otter',
        'kraken', 'leviathan', 'nautilus', 'coral', 'anemone',
        // Places — landforms & geography
        'harbor', 'summit', 'meadow', 'ridge', 'peak',
        'brook', 'stone', 'glade', 'vale', 'den',
        'cliff', 'bluff', 'mesa', 'crag', 'fjord',
        'tundra', 'reef', 'delta', 'boulder', 'ravine',
        'willow', 'monolith', 'torrent', 'grove', 'canopy',
        'haven', 'crest', 'thicket', 'lodge', 'shadow',
        'canyon', 'cavern', 'chasm', 'gorge', 'gully',
        'dune', 'oasis', 'plateau', 'prairie', 'savanna',
        'steppe', 'basin', 'col', 'dell', 'knoll',
        'headland', 'inlet', 'isthmus', 'peninsula', 'strait',
        'wetland', 'woodland', 'arbor', 'archipelago', 'atoll',
        'estuary', 'lagoon', 'isle', 'marina', 'quay',
        'volcano', 'caldera', 'geyser', 'spring', 'cascade',
        'waterfall', 'rapids', 'shoal', 'bank', 'shore',
        // Places — settlements & structures
        'hamlet', 'village', 'outpost', 'citadel', 'fortress',
        'tower', 'keep', 'gate', 'bridge', 'crossing',
        'trail', 'path', 'road', 'pass', 'camp',
        'lighthouse', 'beacon', 'watchtower', 'monastery', 'temple',
        // Trees & plants
        'spruce', 'cedar', 'pine', 'oak', 'birch',
        'maple', 'willow', 'aspen', 'redwood', 'sequoia',
        'bamboo', 'palm', 'cypress', 'juniper', 'hemlock',
        'magnolia', 'dogwood', 'sycamore', 'elm', 'ash',
        'fern', 'moss', 'ivy', 'heather', 'thistle',
        'orchid', 'lily', 'iris', 'rose', 'violet',
        'lotus', 'blossom', 'bloom', 'petal', 'canopy',
        // Celestial & weather
        'comet', 'meteor', 'nova', 'nebula', 'galaxy',
        'orbit', 'eclipse', 'aurora', 'horizon', 'zenith',
        'blizzard', 'gale', 'storm', 'cyclone', 'monsoon',
        'thunder', 'lightning', 'hail', 'mist', 'frost',
        'drift', 'tide', 'wave', 'current', 'spray',
        'sky', 'cloud', 'star', 'moon', 'sun',
        // Objects & tools
        'signal', 'anchor', 'cinder', 'ember', 'forge',
        'pioneer', 'echo', 'blaze', 'quartz', 'crystal',
        'anvil', 'hammer', 'blade', 'sword', 'shield',
        'spear', 'arrow', 'bow', 'dagger', 'axe',
        'bell', 'drum', 'horn', 'flute', 'harp',
        'book', 'scroll', 'map', 'chart', 'compass',
        'lens', 'mirror', 'prism', 'orb', 'gem',
        'jewel', 'crown', 'ring', 'coin', 'key',
        'lock', 'chain', 'rope', 'hook', 'anchor',
        'lantern', 'torch', 'candle', 'beacon', 'lamp',
        'clock', 'hourglass', 'chronometer', 'sundial', 'gear',
        'cog', 'wheel', 'axle', 'lever', 'pulley',
        'spring', 'coil', 'piston', 'valve', 'engine',
        'motor', 'rocket', 'capsule', 'satellite', 'probe',
        'telescope', 'microscope', 'barometer', 'sextant', 'astrolabe',
        'barrel', 'bucket', 'crate', 'chest', 'vault',
        'banner', 'flag', 'pennant', 'badge', 'medal',
        'throne', 'altar', 'pillar', 'column', 'arch',
        'dome', 'spire', 'obelisk', 'monument', 'statue',
        // Nautical
        'skipper', 'buoy', 'galley', 'helm', 'hull',
        'mast', 'deck', 'hold', 'keel', 'dock',
        'pier', 'jetty', 'wharf', 'mooring', 'rudder',
        'tiller', 'prow', 'stern', 'brig', 'frigate',
        'corvette', 'galleon', 'schooner', 'trawler', 'cutter',
        'lifeboat', 'dory', 'sailor', 'pilot', 'navigator',
        'voyage', 'harpoon', 'foghorn', 'leadline', 'drifter',
        'sirena', 'neptune', 'draught', 'fluke', 'bosun',
    ];

    public static function generate(?string $exclude = null): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $name = Str::slug(
                self::ADJECTIVES[array_rand(self::ADJECTIVES)].'-'.self::NOUNS[array_rand(self::NOUNS)]
            );

            if ($exclude === null || $name !== $exclude) {
                return $name;
            }
        }

        return Str::slug(
            self::ADJECTIVES[array_rand(self::ADJECTIVES)].'-'.self::NOUNS[array_rand(self::NOUNS)].'-'.Str::lower(Str::random(3))
        );
    }
}
