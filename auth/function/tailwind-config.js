/* ==========================================================
   tailwind-config.js - loaded right after the Tailwind CDN script on
   every auth page. Holds the shared theme, then pulls in the shared
   component classes (auth/frontend/auth.tw.css) as a
   <style type="text/tailwindcss"> block, the only form the CDN reads.
   ========================================================== */

tailwind.config = { theme: { extend: {
  colors: {
    teal: { DEFAULT: '#006D6F', dark: '#06464A', deep: '#043437', glow: '#0B8C8E' },
    sand: '#E8DCC8', gold: '#C9A24E', cream: '#FFFDF9', field: '#F6F0E6',
    ink: '#1F2937', mute: '#5B6472', line: '#E4D7C0', ok: '#2F7D4F', err: '#A12B2B'
  },
  fontFamily: {
    display: ['"Playfair Display"', 'Georgia', 'serif'],
    body: ['"Plus Jakarta Sans"', 'system-ui', 'sans-serif']
  },
  keyframes: {
    drift: { '0%,100%': { transform: 'translate(0,0) scale(1)' }, '50%': { transform: 'translate(60px,-40px) scale(1.12)' } },
    drift2: { '0%,100%': { transform: 'translate(0,0) scale(1)' }, '50%': { transform: 'translate(-70px,50px) scale(.9)' } },
    rise: { '0%': { transform: 'translateY(0)', opacity: '0' }, '15%': { opacity: '.7' }, '100%': { transform: 'translateY(-110vh)', opacity: '0' } },
    sweep: { '0%': { strokeDashoffset: '2400' }, '100%': { strokeDashoffset: '0' } },
    shimmer: { '0%': { transform: 'translateX(-120%) rotate(12deg)' }, '100%': { transform: 'translateX(320%) rotate(12deg)' } },
    pop: { '0%': { opacity: '0', transform: 'translateY(10px)' }, '100%': { opacity: '1', transform: 'none' } },
    fan: { '0%': { transform: 'rotate(0deg)' }, '100%': { transform: 'rotate(var(--r))' } }
  },
  animation: {
    drift: 'drift 18s ease-in-out infinite',
    drift2: 'drift2 22s ease-in-out infinite',
    rise: 'rise linear infinite',
    sweep: 'sweep 4s cubic-bezier(.6,0,.2,1) forwards',
    shimmer: 'shimmer 9s ease-in-out infinite',
    'spin-slow': 'spin 60s linear infinite',
    pop: 'pop .4s ease-out both',
    fan: 'fan .9s cubic-bezier(.2,.8,.2,1) both'
  }
} } };

/* Synchronous on purpose: a block added after the CDN's first build races
   its async rebuilds and can be dropped, leaving the page unstyled. */
(function () {
  const xhr = new XMLHttpRequest();
  xhr.open('GET', new URL('../frontend/auth.tw.css', document.currentScript.src), false);
  xhr.send();
  document.write('<style type="text/tailwindcss">' + xhr.responseText + '</style>');
})();
