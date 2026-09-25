import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './tokens.css';
import './homepage.css';
import { HomePage } from './HomePage';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <HomePage />
  </StrictMode>
);
