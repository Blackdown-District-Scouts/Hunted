// Dumb broadcast relay. Any message from one admin is forwarded to all the
// others, who react by re-fetching their page's live region from PHP.
// It holds no game state — PHP + MySQL remain the source of truth.
const { WebSocketServer } = require('ws');

const PORT = process.env.PORT || 8081;
const wss = new WebSocketServer({ port: PORT });

wss.on('connection', (ws) => {
  ws.on('message', (data) => {
    const msg = data.toString();
    for (const client of wss.clients) {
      if (client !== ws && client.readyState === 1 /* OPEN */) {
        client.send(msg);
      }
    }
  });
  ws.on('error', () => { /* ignore */ });
});

// Lightweight keepalive so idle connections aren't dropped by proxies.
setInterval(() => {
  for (const client of wss.clients) {
    if (client.readyState === 1) { try { client.ping(); } catch (e) {} }
  }
}, 30000);

console.log('Hunted WS relay listening on ' + PORT);
