import brand from './assets/brand.js';

export default {
  content: ['./templates/**/*.twig', './assets/**/*.js'],
  theme: { extend: { colors: brand } },
  plugins: [],
};
