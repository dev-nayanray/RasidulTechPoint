/** @type {import('tailwindcss').Config} */
module.exports = {
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
          700: '#14243a', // primary navy
          800: '#101d30',
          900: '#0c1626',
        },
        brand: {
          blue: '#2563EB',
          green: '#16A34A',
        },
      },
      fontFamily: {
        sans: ['Inter', 'Segoe UI', 'Noto Sans Bengali', 'system-ui', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
