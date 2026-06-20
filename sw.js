self.addEventListener('install', function(event) {
    console.log('Service Worker установлен');
});

self.addEventListener('fetch', function(event) {
    event.respondWith(fetch(event.request));
});