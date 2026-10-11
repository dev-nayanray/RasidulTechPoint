/**
 * Rasidul Tech Point — frontend entry point (ES modules, no framework).
 * Each concern lives in its own module; wiring happens through data-* attributes.
 *
 * Imports are resolved relative to this file's URL (/assets/js/app.js), so a
 * bare `./nav` resolves to /assets/js/nav.js — never use a `./js/...` prefix
 * here (that would silently 404 in the browser and the modules would never run).
 */
import './nav';
import './dropdown';
import './modal';
import './confirm';
import './toast';
import './forms';
import './order-form';
import './mobile-search';
import './page-progress';
