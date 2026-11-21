import type { CapacitorConfig } from '@capacitor/cli';

/*
 * The app is a native shell around the website: it opens SERVER_URL (bewelcome.org by default) in a
 * full-screen web view, so every page and every change to the site is in the app at once, without a release.
 * www/ only holds the page shown when the site can't be reached.
 *
 * Local test against the Docker site: SERVER_URL=http://10.0.2.2:8081 npx cap sync android
 * (10.0.2.2 is the computer as seen from the Android emulator; on a phone use the computer's LAN address).
 */
const serverUrl = process.env.SERVER_URL ?? 'https://bewelcome.org';
const local = serverUrl.startsWith('http://');

const config: CapacitorConfig = {
    appId: 'org.bewelcome.app',
    appName: 'BeWelcome',
    webDir: 'www',
    server: {
        url: serverUrl,
        // Plain http only for a local test server
        cleartext: local,
        // Pages of these hosts stay in the app; any other link opens in the browser
        allowNavigation: ['bewelcome.org', '*.bewelcome.org', ...(local ? [new URL(serverUrl).host] : [])],
        errorPath: 'offline.html',
    },
    // The site can tell the app from a browser (".is-app" styles, no "get the app" banners...)
    appendUserAgent: 'BeWelcomeApp',
    backgroundColor: '#ffffff',
    android: {
        allowMixedContent: local,
    },
    plugins: {
        StatusBar: {
            backgroundColor: '#f37000',
            style: 'DARK',
        },
    },
};

export default config;
