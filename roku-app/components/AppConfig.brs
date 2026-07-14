function AppConfig() as Object
    apiBaseUrl = GetConfiguredApiBaseUrl()
    return {
        appName: "WordPress Foyer",
        appVersion: "1.0.0",
        registrySection: "WordPressFoyer",
        defaultApiBaseUrl: apiBaseUrl,
        cacheDir: "cachefs:/wordpress-foyer",
        manifestCachePath: "cachefs:/wordpress-foyer/manifest.json",
        minRefreshSeconds: 15,
        maxRefreshSeconds: 3600,
        defaultRefreshSeconds: 60,
        defaultSlideDurationSeconds: 8,
        minSlideDurationSeconds: 2,
        maxCacheFiles: 40,
        requestTimeoutSeconds: 20,
        imageDownloadTimeoutSeconds: 30
    }
end function
