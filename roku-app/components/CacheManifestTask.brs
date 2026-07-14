sub init()
    m.top.functionName = "RunCacheManifestTask"
end sub

sub RunCacheManifestTask()
    mode = m.top.mode
    if mode = "load"
        m.top.result = LoadCachedManifestResult()
    else if mode = "save"
        m.top.result = SaveCachedManifestResult(m.top.manifest)
    else
        m.top.result = { ok: false, mode: mode, error: "unknown cache mode" }
    end if
end sub

function LoadCachedManifestResult() as Object
    config = AppConfig()
    if not EnsureCacheDir(config.cacheDir) then
        return { ok: false, mode: "load", error: "cache unavailable" }
    end if

    text = ReadTextFile(config.manifestCachePath)
    if text = invalid or text = "" then
        return { ok: false, mode: "load", error: "no cached manifest" }
    end if

    raw = ParseJson(text)
    normalized = NormalizeManifest(raw, m.top.displaySlug)
    if not normalized.ok then
        return { ok: false, mode: "load", error: normalized.error }
    end if
    cachedManifest = PrepareCachedManifest(normalized.manifest)
    if cachedManifest = invalid then
        return { ok: false, mode: "load", error: "cached images unavailable" }
    end if

    return { ok: true, mode: "load", manifest: cachedManifest }
end function

function PrepareCachedManifest(manifest as Object) as Dynamic
    fs = CreateObject("roFileSystem")
    readySlides = []

    for each slide in manifest.slides
        cachePath = SafeString(slide.cachePath, "")
        if cachePath <> "" and fs.Exists(cachePath)
            slide.cachedUrl = cachePath
            readySlides.Push(slide)
        end if
    end for

    if readySlides.Count() = 0 then return invalid
    manifest.slides = readySlides
    return manifest
end function

function SaveCachedManifestResult(manifest as Dynamic) as Object
    config = AppConfig()
    if type(manifest) <> "roAssociativeArray" then
        return { ok: false, mode: "save", error: "missing manifest" }
    end if
    if not EnsureCacheDir(config.cacheDir) then
        return { ok: false, mode: "save", error: "cache unavailable" }
    end if

    if WriteTextFile(config.manifestCachePath, FormatJson(manifest)) then
        return { ok: true, mode: "save" }
    end if

    return { ok: false, mode: "save", error: "manifest write failed" }
end function
