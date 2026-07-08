import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });

createInertiaApp({
  resolve: (name) => {
    const page = pages[`./Pages/${name}.jsx`];

    if (!page) {
      throw new Error(`Unknown Inertia page: ${name}`);
    }

    return page.default;
  },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props} />);
  },
});
