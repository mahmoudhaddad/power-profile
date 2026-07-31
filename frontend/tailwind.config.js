import defaultTheme from 'tailwindcss/defaultTheme'

/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        base: '#0a0b0d',
        surface: {
          alt: '#0f1114',
          card: '#15181c',
          deep: '#0d0f12',
          darker: '#0a0c0e',
          inset: '#191c20',
          inset2: '#101317',
          inset3: '#131619',
        },
        line: {
          subtle: 'rgba(255,255,255,0.06)',
          DEFAULT: 'rgba(255,255,255,0.08)',
          strong: 'rgba(255,255,255,0.14)',
        },
        accent: {
          DEFAULT: '#f4b23e',
          from: '#f7bd52',
          to: '#eda52c',
          light: '#ffd77a',
          lighter: '#ffe0a0',
          bright: '#ffca63',
          soft: 'rgba(244,178,62,0.08)',
          softer: 'rgba(244,178,62,0.16)',
          tint: 'rgba(244,178,62,0.25)',
          border: 'rgba(244,178,62,0.3)',
          'border-strong': 'rgba(244,178,62,0.55)',
        },
        ink: {
          heading: '#f4f5f7',
          heading2: '#f2f3f5',
          body: '#9aa0aa',
          body2: '#9298a2',
          body3: '#8a919b',
          muted: '#6b727c',
          muted2: '#5b626c',
          muted3: '#7b828c',
          data: '#c8ccd2',
          data2: '#d5d8dc',
        },
        danger: {
          DEFAULT: '#c2645f',
          soft: 'rgba(194,101,95,0.12)',
          border: 'rgba(194,101,95,0.4)',
        },
        success: {
          DEFAULT: '#7fae7a',
          soft: 'rgba(127,174,122,0.12)',
          border: 'rgba(127,174,122,0.4)',
        },
      },
      fontFamily: {
        heading: ['"Space Grotesk"', ...defaultTheme.fontFamily.sans],
        sans: ['"IBM Plex Sans"', ...defaultTheme.fontFamily.sans],
        mono: ['"IBM Plex Mono"', ...defaultTheme.fontFamily.mono],
      },
      boxShadow: {
        accent: '0 0 20px -6px rgba(244,178,62,0.6)',
        'accent-strong': '0 0 20px -6px rgba(244,178,62,0.7)',
      },
      backgroundImage: {
        'accent-gradient': 'linear-gradient(135deg, #f7bd52, #eda52c)',
      },
    },
  },
  plugins: [],
}
