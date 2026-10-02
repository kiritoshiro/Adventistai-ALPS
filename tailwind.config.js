module.exports = {
  content: ['./index.php', './app/**/*.php', './resources/**/*.{php,vue,js}'],
  // Post content and custom HTML blocks may use it; no template does.
  safelist: ['sr-only'],
  theme: {
    extend: {
      colors: {},
    },
  },
  plugins: [],
};
