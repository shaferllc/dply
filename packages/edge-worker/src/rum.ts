export const VITALS_BEACON_PATH = '/__dply/vitals';

export function shouldInjectRum(requestPath: string, hasIngest: boolean): boolean {
  if (! hasIngest) {
    return false;
  }

  return requestPath === 'index.html' || requestPath.endsWith('.html');
}

export function injectRumScript(html: string): string {
  const script = `<script>${RUM_SCRIPT_SOURCE}</script>`;

  if (html.includes('</body>')) {
    return html.replace('</body>', `${script}</body>`);
  }

  if (html.includes('</head>')) {
    return html.replace('</head>', `${script}</head>`);
  }

  return `${html}${script}`;
}

const RUM_SCRIPT_SOURCE = `(function(){var e="${VITALS_BEACON_PATH}",n=!1,t={path:location.pathname||"/"};function r(e){return typeof e==="number"&&isFinite(e)&&e>0?Math.round(e):null}function o(){if(n)return;n=!0;var o=performance.getEntriesByType("navigation");if(o&&o[0]){var a=o[0];t.ttfb_ms=r(a.responseStart)}try{var i=performance.getEntriesByType("paint");for(var c=0;c<i.length;c++)i[c].name==="first-contentful-paint"&&(t.fcp_ms=r(i[c].startTime))}catch(e){}fetch(e,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(t),keepalive:!0,credentials:"same-origin"})}try{new PerformanceObserver(function(e){var n=e.getEntries(),o=n[n.length-1];o&&(t.lcp_ms=r(o.startTime))}).observe({type:"largest-contentful-paint",buffered:!0})}catch(e){}try{var a=0;new PerformanceObserver(function(e){for(var n=0;n<e.getEntries().length;n++)e.getEntries()[n].hadRecentInput||(a+=e.getEntries()[n].value);t.cls=Math.round(a*1e4)/1e4}).observe({type:"layout-shift",buffered:!0})}catch(e){}try{new PerformanceObserver(function(e){var n=e.getEntries();n.length&&(t.inp_ms=r(n[n.length-1].duration))}).observe({type:"event",buffered:!0,durationThreshold:40})}catch(e){}document.addEventListener("visibilitychange",function(){document.visibilityState==="hidden"&&o()});addEventListener("pagehide",o);setTimeout(o,1e4)})();`;
