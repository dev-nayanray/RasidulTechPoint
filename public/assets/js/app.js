document.documentElement.setAttribute("data-app-ran","1");
try{__appErr=null;}catch(e){}
/**
 * Rasidul Tech Point — frontend entry point (ES modules, no framework).
 * Each concern lives in its own module; wiring happens through data-* attributes.
 *
 * Imports are resolved relative to this file's URL (/assets/js/app.js) and MUST
 * carry the explicit `.js` extension: browsers never append one, and neither the
 * PHP router nor Apache maps `/assets/js/nav` to `nav.js`, so an extensionless
 * import 404s and silently stops every module (drawer, dropdowns, theme toggle).
 * Never use a `./js/...` prefix here either.
 */
import './nav.js';
import './admin-responsive.js';
import './dropdown.js';
import './modal.js';
import './confirm.js';
import './toast.js';
import './forms.js';
import './order-form.js';
import './mobile-search.js';
import './page-progress.js';
import './product-gallery.js';
import './compare.js';
import './chart.js';
