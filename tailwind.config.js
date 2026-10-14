/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: 'class',
  content: [
    './resources/views/**/*.php',
    './resources/js/**/*.js',
    './public/assets/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        navy: {
          50: '#f0f4f8',
          100: '#dae0ea',
          200: '#b8c3d4',
          300: '#8ea0b8',
          400: '#5e7694',
          500: '#3b5473',
          600: '#283d59',
          700: '#14243a',
          800: '#101d30',
          900: '#0c1626',
        },
        brand: {
          blue: '#2563EB',
          green: '#16A34A',
        },
        indigo: {
          50: '#eef2ff',
          100: '#e0e7ff',
          200: '#c7d2fe',
          300: '#a5b4fc',
          400: '#818cf8',
          500: '#6366f1',
          600: '#4f46e5',
          700: '#4338ca',
          800: '#3730a3',
          900: '#312e81',
        },
      },
      fontFamily: {
        sans: ['Inter', 'Segoe UI', 'Noto Sans Bengali', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
