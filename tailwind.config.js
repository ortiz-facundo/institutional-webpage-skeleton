/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './templates/**/*.{html,twig,js,jsx,ts,tsx,vue}',
    './assets/**/*.{js,jsx,ts,tsx,vue}',
  ],
  // Tailwind's content scanner can't see runtime values, so the
  // initials-avatar palette (assembled by App\Twig\UserAvatarExtension)
  // must be listed here to ensure the bg-* classes make it into the build.
  // Keep this list in sync with UserAvatarExtension::PALETTE.
  safelist: [
    'bg-slate-700', 'bg-stone-700', 'bg-zinc-700', 'bg-neutral-700',
    'bg-rose-800', 'bg-emerald-800', 'bg-teal-800', 'bg-sky-800',
    'bg-indigo-800', 'bg-amber-800',
  ],
  theme: {
    extend: {},
  },
  plugins: [],
};
