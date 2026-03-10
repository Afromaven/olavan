<?php
/**
 * Olavan - Service Worker for PWA functionality
 * Location: C:/xampp/htdocs/olavan/sw.php
 */

header('Content-Type: application/javascript');
header('Service-Worker-Allowed: /olavan/');
?>
// Service Worker for Olavan PWA
const CACHE_NAME = 'olavan-v1';
const urlsToCache = [
    '/olavan/',
    '/olavan/index.php',
    '/olavan/user.php',
    '/olavan/admin.php',
    '/olavan/db.php',
    '/olavan/logout.php',
    // Your actual icon files
    '/olavan/uploads/icons/apple-touch-icon.png',
    '/olavan/uploads/icons/favicon-32x32.png',
    '/olavan/uploads/icons/favicon-16x16.png',
    '/olavan/uploads/icons/android-chrome-192x192.png',
    '/olavan/uploads/icons/android-chrome-512x512.png',
    '/olavan/uploads/icons/favicon.ico',
    '/olavan/uploads/icons/site.webmanifest',
    '/olavan/uploads/icons/safari-pinned-tab.svg',
    // External resources
    'https://fonts.googleapis.com/icon?family=Material+Icons',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'
];

// Install event - cache assets
self.addEventListener('install', event => {
    console.log('Service Worker: Installing...');
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Service Worker: Caching files');
                return cache.addAll(urlsToCache);
            })
            .then(() => self.skipWaiting())
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
    console.log('Service Worker: Activating...');
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cache => {
                    if (cache !== CACHE_NAME) {
                        console.log('Service Worker: Clearing old cache', cache);
                        return caches.delete(cache);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event - serve from cache, fallback to network
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    
    // Only cache our own domain and specific CDNs
    if (!url.pathname.startsWith('/olavan/') && 
        !url.hostname.includes('fonts.googleapis.com') &&
        !url.hostname.includes('cdnjs.cloudflare.com') &&
        !url.hostname.includes('cdn.jsdelivr.net')) {
        return;
    }

    event.respondWith(
        caches.match(event.request)
            .then(response => {
                // Cache hit - return response
                if (response) {
                    return response;
                }

                // Clone the request
                const fetchRequest = event.request.clone();

                return fetch(fetchRequest).then(response => {
                    // Check if valid response
                    if (!response || response.status !== 200) {
                        return response;
                    }

                    // Clone the response
                    const responseToCache = response.clone();

                    caches.open(CACHE_NAME).then(cache => {
                        // Cache static assets only (not dynamic pages)
                        if (url.pathname.includes('.png') || 
                            url.pathname.includes('.ico') ||
                            url.pathname.includes('.svg') ||
                            url.pathname.includes('.css') ||
                            url.pathname.includes('.js')) {
                            cache.put(event.request, responseToCache);
                        }
                    });

                    return response;
                }).catch(error => {
                    // If offline and it's a page navigation, show offline page
                    if (event.request.mode === 'navigate') {
                        return caches.match('/olavan/offline.html');
                    }
                    return new Response('Offline', { status: 503 });
                });
            })
    );
});