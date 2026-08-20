import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css', 'resources/js/app.js',
                'resources/css/public.css', 'resources/js/public.js',
            ],
            refresh: true,
            // Ohne das benutzt der Vite-Dev-Server ein eigenes, selbstsigniertes Zertifikat für
            // Port 5173 — der Browser vertraut nur dem von Herd für die Domain selbst, blockiert
            // also CSS/JS von dort lautlos. detectTls verwendet Herds (bzw. Valets) Zertifikat
            // für die aktuelle Site auch für den Dev-Server mit.
            detectTls: true,
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
