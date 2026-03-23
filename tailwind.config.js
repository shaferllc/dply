import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            colors: {
                brand: {
                    ink: '#171A0E',
                    forest: '#32482C',
                    moss: '#5D6259',
                    sage: '#688479',
                    olive: '#5D5622',
                    rust: '#9A6215',
                    copper: '#AC6E22',
                    gold: '#CDA942',
                    sand: '#E1D8AC',
                    cream: '#FDFCF9',
                    mist: '#A7A69A',
                },
            },
            fontFamily: {
                sans: ['Instrument Sans', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            backgroundImage: {
                'mesh-brand':
                    'radial-gradient(at 40% 20%, rgba(205, 169, 66, 0.12) 0px, transparent 50%), radial-gradient(at 80% 0%, rgba(104, 132, 121, 0.14) 0px, transparent 45%), radial-gradient(at 0% 50%, rgba(50, 72, 44, 0.08) 0px, transparent 50%)',
            },
        },
    },

    plugins: [forms],
};
