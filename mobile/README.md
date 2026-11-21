# BeWelcome app (Capacitor)

A native shell around the website, for Android now and iOS later. The app opens
https://www.bewelcome.org in a full-screen web view (`server.url` in `capacitor.config.ts`), so the
site and every change to it are in the app without a new release. `www/` only holds the page shown
when the site can't be reached.

- App id: `org.bewelcome.app`, name: BeWelcome.
- The web view's user agent ends with `BeWelcomeApp`, so the site can tell the app from a browser.
- Links to other sites open in the browser; bewelcome.org pages stay in the app.
- Android's back button goes back in the pages, then closes the app (`@capacitor/app`).

## Build for Android

Needs Node 22+, Android Studio (with an Android SDK) and JDK 21 (the one inside Android Studio is fine).

```sh
cd mobile
npm install
npx cap sync android        # writes the config and the plugins into android/
npx cap open android        # opens Android Studio: Run ▶ on an emulator or a phone
```

A debug APK from the command line: `cd android && ./gradlew assembleDebug`
(then `android/app/build/outputs/apk/debug/app-debug.apk`).

### Against the local Docker site

```sh
SERVER_URL=http://10.0.2.2:8081 npx cap sync android
```

`10.0.2.2` is the computer as seen from the Android emulator; on a phone, use the computer's address
on the Wi-Fi (`http://192.168.x.x:8081`). Run `npx cap sync android` again without `SERVER_URL` to
go back to the real site.

## Icon and splash

`assets/` holds the sources (white BeWelcome mark on the orange #f37000, made from
`public/images/logo_icon_2025.svg` and `logo_full_2025.svg`): `icon-only.png`, `icon-foreground.png`
and `icon-background.png` (1024×1024, adaptive icon), `splash.png` and `splash-dark.png` (2732×2732).
After changing them: `npm run assets` (writes `android/app/src/main/res`).

The launch screen is native: the system splash shows the mark on orange (`res/drawable/splash_icon.xml`),
then `MainActivity` draws an orange overlay where the mark moves left and the name slides in
(`splash_mark.xml`, `splash_name.xml`, vector paths from `logo_full_2025.svg`). The overlay fades out
once the first page has loaded (at least 1.4 s, at most 6 s).

## Later

- iOS: `npm install @capacitor/ios && npx cap add ios` (needs a Mac with Xcode).
- Push notifications (`@capacitor/push-notifications`, Firebase on Android).
- Store release: a signing key, then `./gradlew bundleRelease` for Google Play.
