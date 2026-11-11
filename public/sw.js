// Service worker do Treinr: deixa o app instalável, guarda os arquivos do app (CSS/JS/ícones)
// para abrir mais rápido e mostra uma tela amigável quando não há internet.
// Páginas e dados NÃO são guardados (sempre vêm do servidor, já atualizados).
const VERSION = 'treinr-v2';
const SHELL = ['/offline.html', '/icon-192.png', '/icon-512.png', '/favicon.svg'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Arquivos do build têm hash no nome: podem vir direto do cache.
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(
            caches.match(req).then(
                (hit) =>
                    hit ||
                    fetch(req).then((res) => {
                        if (res.ok) {
                            const copy = res.clone();
                            caches.open(VERSION).then((c) => c.put(req, copy));
                        }
                        return res;
                    }),
            ),
        );
        return;
    }

    // Abrir uma página sem internet: mostra a tela "Sem conexão".
    if (req.mode === 'navigate') {
        event.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
    }
});

// Tocar na notificação (ex.: fim do descanso) volta para o app.
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            const open = list.find((c) => 'focus' in c);
            return open ? open.focus() : self.clients.openWindow(event.notification.data?.url || '/dashboard');
        }),
    );
});
