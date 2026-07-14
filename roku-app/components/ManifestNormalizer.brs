function NormalizeDisplayList(raw as Dynamic) as Object
    result = { ok: false, displays: [], error: "" }
    if type(raw) <> "roAssociativeArray" then
        result.error = "display list is not an object"
        return result
    end if
    if type(raw.displays) <> "roArray" then
        result.error = "display list missing displays"
        return result
    end if

    displays = []
    for each item in raw.displays
        if type(item) = "roAssociativeArray"
            slug = SafeString(item.slug, "")
            name = SafeString(item.name, slug)
            if slug <> "" and name <> "" then
                displays.Push({ id: item.id, slug: slug, name: name })
            end if
        end if
    end for

    result.ok = displays.Count() > 0
    result.displays = displays
    if not result.ok then result.error = "no displays returned"
    return result
end function

function NormalizeManifest(raw as Dynamic, expectedSlug as String) as Object
    config = AppConfig()
    result = { ok: false, manifest: invalid, error: "" }

    if type(raw) <> "roAssociativeArray" then
        result.error = "manifest is not an object"
        return result
    end if
    if raw.schemaVersion <> 1 then
        result.error = "unsupported schema"
        return result
    end if
    if type(raw.display) <> "roAssociativeArray" or SafeString(raw.display.slug, "") <> expectedSlug then
        result.error = "display mismatch"
        return result
    end if
    if type(raw.slides) <> "roArray" then
        result.error = "slides missing"
        return result
    end if

    revision = SafeString(raw.revision, "")
    if revision = "" then
        result.error = "manifest revision missing"
        return result
    end if

    channelDuration = config.defaultSlideDurationSeconds
    transition = "fade"
    transitionDuration = 0.0
    channelName = ""

    if type(raw.channel) = "roAssociativeArray"
        channelDuration = ClampNumber(raw.channel.durationSeconds, config.defaultSlideDurationSeconds, config.minSlideDurationSeconds, 86400)
        transition = LCase(SafeString(raw.channel.transition, "fade"))
        if transition <> "fade" and transition <> "none" and transition <> "slide" then transition = "fade"
        transitionDuration = ClampNumber(raw.channel.transitionDurationSeconds, 0, 0, channelDuration)
        channelName = SafeString(raw.channel.name, "")
    end if

    slides = []
    for each slide in raw.slides
        normalized = NormalizeSlide(slide, channelDuration, transitionDuration)
        if normalized <> invalid then slides.Push(normalized)
    end for

    if slides.Count() = 0 then
        result.error = "no playable slides"
        return result
    end if

    refresh = ClampNumber(raw.refreshSeconds, config.defaultRefreshSeconds, config.minRefreshSeconds, config.maxRefreshSeconds)
    manifest = {
        schemaVersion: 1,
        revision: revision,
        refreshSeconds: refresh,
        display: {
            id: raw.display.id,
            slug: expectedSlug,
            name: SafeString(raw.display.name, expectedSlug)
        },
        channel: {
            name: channelName,
            durationSeconds: channelDuration,
            transition: transition,
            transitionDurationSeconds: transitionDuration
        },
        slides: slides
    }

    result.ok = true
    result.manifest = manifest
    return result
end function

function NormalizeSlide(slide as Dynamic, channelDuration as Float, transitionDuration as Float) as Dynamic
    if type(slide) <> "roAssociativeArray" then return invalid
    if SafeString(slide.type, "") <> "image" then return invalid

    sourceType = SafeString(slide.sourceType, "")
    if sourceType <> "foyer-image" and sourceType <> "foyer-iframe-snapshot" then return invalid

    url = SafeString(slide.url, "")
    if not IsHttpUrl(url) then return invalid

    revision = SafeString(slide.revision, "")
    if revision = "" then return invalid

    idType = type(slide.id)
    if idType <> "roInt" and idType <> "Integer" and idType <> "roFloat" and idType <> "Float" then return invalid

    duration = ClampNumber(slide.durationSeconds, channelDuration, 2, 86400)
    effectiveTransition = transitionDuration
    if effectiveTransition >= duration then effectiveTransition = duration - 0.1
    if effectiveTransition < 0 then effectiveTransition = 0

    fit = LCase(SafeString(slide.fit, "cover"))
    if fit <> "contain" and fit <> "cover" then fit = "cover"

    return {
        id: Int(slide.id),
        type: "image",
        sourceType: sourceType,
        url: url,
        fit: fit,
        revision: revision,
        durationSeconds: duration,
        transitionDurationSeconds: effectiveTransition,
        cachePath: SlideCachePath({ id: Int(slide.id), revision: revision }, AppConfig().cacheDir)
    }
end function

function ManifestHasUsableCachedImages(manifest as Object) as Boolean
    fs = CreateObject("roFileSystem")
    if type(manifest) <> "roAssociativeArray" or type(manifest.slides) <> "roArray" then return false
    for each slide in manifest.slides
        if type(slide) = "roAssociativeArray" and fs.Exists(SafeString(slide.cachePath, "")) then return true
    end for
    return false
end function
