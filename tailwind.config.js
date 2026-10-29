import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Outfit', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                ocean: {
                    50: '#f0f9ff',
                    100: '#e0f2fe',
                    200: '#bae6fd',
                    300: '#7dd3fc',
                    400: '#38bdf8',
                    500: '#0ea5e9',
                    600: '#0284c7',
                    700: '#0369a1',
                    800: '#075985',
                    900: '#0c4a6e',
                },
                reef: {
                    50: '#f0fdfa',
                    100: '#ccfbf1',
                    200: '#99f6e4',
                    300: '#5eead4',
                    400: '#2dd4bf',
                    500: '#14b8a6',
                    600: '#0d9488',
                    700: '#0f766e',
                    800: '#115e59',
                    900: '#134e4a',
                },
                sand: {
                    50: '#faf6f0',
                    100: '#f2eade',
                    200: '#dfcfb4',
                    300: '#c9b394',
                    400: '#bda57f',
                    500: '#c4ab7c',
                    600: '#b89763',
                    700: '#9a7d52',
                    800: '#7d6444',
                    900: '#655138',
                },
                wave: {
                    50: '#f5f7ff',
                    100: '#eef2ff',
                    200: '#e0e7ff',
                    300: '#c7d2fe',
                    400: '#a5b4fc',
                    500: '#818cf8',
                    600: '#6366f1',
                    700: '#4f46e5',
                    800: '#4338ca',
                    900: '#3730a3',
                },
                deep: {
                    700: '#155e75',
                    800: '#0e485e',
                    900: '#083344',
                    950: '#062633',
                },
                lagoon: {
                    50: '#f0fdfa',
                    100: '#ccfbf1',
                    200: '#99f6e4',
                    300: '#5eead4',
                    400: '#2dd4bf',
                    500: '#14b8a6',
                    600: '#0d9488',
                    700: '#0f766e',
                    800: '#115e59',
                },
                coral: {
                    50: '#fff7ed',
                    100: '#ffedd5',
                    200: '#fed7aa',
                    300: '#fdba74',
                    400: '#fb923c',
                    500: '#f97316',
                    600: '#ea580c',
                    700: '#c2410c',
                    800: '#9a3412',
                    900: '#7c2d12',
                },
            },
            borderRadius: {
                control: '10px',
                card: '16px',
                sheet: '20px',
            },
            boxShadow: {
                'card': '0 1px 2px rgba(8, 51, 68, 0.06), 0 4px 10px -4px rgba(8, 51, 68, 0.12)',
                'lift': '0 2px 4px rgba(8, 51, 68, 0.07), 0 16px 28px -10px rgba(8, 51, 68, 0.18)',
                'ocean': '0 4px 20px -2px rgba(14, 165, 233, 0.25)',
                'reef': '0 4px 20px -2px rgba(20, 184, 166, 0.25)',
                'sand': '0 4px 20px -2px rgba(196, 171, 124, 0.25)',
                'wave': '0 4px 20px -2px rgba(129, 140, 248, 0.25)',
                'glow-ocean': '0 0 40px -10px rgba(14, 165, 233, 0.5)',
                'glow-reef': '0 0 40px -10px rgba(20, 184, 166, 0.5)',
            },
            fontSize: {
                'label': ['12px', { lineHeight: '16px', letterSpacing: '0.02em' }],
                'body-sm': ['13px', { lineHeight: '20px' }],
                'body': ['14px', { lineHeight: '22px' }],
                'body-lg': ['16px', { lineHeight: '26px' }],
                'title': ['18px', { lineHeight: '28px' }],
                'headline': ['24px', { lineHeight: '32px' }],
                'display': ['32px', { lineHeight: '40px' }],
                'hero': ['40px', { lineHeight: '48px' }],
            },
            maxWidth: {
                shell: '1440px',
            },
            transitionTimingFunction: {
                out: 'cubic-bezier(0.22, 1, 0.36, 1)',
            },
            animation: {
                'wave': 'wave 3s ease-in-out infinite',
                'float': 'float 6s ease-in-out infinite',
                'pulse-slow': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                'shimmer': 'shimmer 2s linear infinite',
            },
            keyframes: {
                wave: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-10px)' },
                },
                float: {
                    '0%, 100%': { transform: 'translateY(0) rotate(0deg)' },
                    '50%': { transform: 'translateY(-20px) rotate(2deg)' },
                },
                shimmer: {
                    '0%': { backgroundPosition: '-200% 0' },
                    '100%': { backgroundPosition: '200% 0' },
                },
            },
            backgroundImage: {
                'gradient-ocean': 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%)',
                'gradient-reef': 'linear-gradient(135deg, #14b8a6 0%, #0d9488 50%, #0f766e 100%)',
                'gradient-sand': 'linear-gradient(135deg, #f7f3eb 0%, #e0d4ba 100%)',
                'gradient-wave': 'linear-gradient(135deg, #818cf8 0%, #6366f1 50%, #4f46e5 100%)',
                'shimmer': 'linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent)',
            },
        },
    },

    plugins: [forms, typography],
};
