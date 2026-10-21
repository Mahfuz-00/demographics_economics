/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        ink: {
          50: '#f6f7f9',
          100: '#eceef2',
          200: '#d5d9e2',
          300: '#b0b8c8',
          400: '#8592a9',
          500: '#66738d',
          600: '#515c74',
          700: '#424b5e',
          800: '#394050',
          900: '#333845',
        },
      },
    },
  },
  plugins: [],
}
