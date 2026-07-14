sub init()
    m.top.focusable = true
    m.config = AppConfig()
    m.currentPoster = m.top.FindNode("currentPoster")
    m.nextPoster = m.top.FindNode("nextPoster")
    m.statusGroup = m.top.FindNode("statusGroup")
    m.statusText = m.top.FindNode("statusText")
    m.slideTimer = m.top.FindNode("slideTimer")
    m.refreshTimer = m.top.FindNode("refreshTimer")
    m.fadeAnimation = m.top.FindNode("fadeAnimation")
    m.fadeIn = m.top.FindNode("fadeIn")
    m.fadeOut = m.top.FindNode("fadeOut")

    m.slideTimer.ObserveField("fire", "OnSlideTimer")
    m.refreshTimer.ObserveField("fire", "OnRefreshTimer")
    m.nextPoster.ObserveField("loadStatus", "OnNextPosterLoadStatus")
    m.fadeAnimation.ObserveField("state", "OnFadeAnimationState")

    m.manifestTask = invalid
    m.imageTask = invalid
    m.cacheTask = invalid
    m.manifest = invalid
    m.pendingManifest = invalid
    m.slides = []
    m.currentIndex = 0
    m.nextIndex = 0
    m.paused = false
    m.refreshInFlight = false
    m.lastError = ""
    m.lastSuccess = ""
    m.networkMode = "live"
    m.transitionPending = false
    m.loadingNext = false
    m.activeDisplaySlug = ""
end sub

sub StartPlayer()
    ResetPlayerState()
    m.activeDisplaySlug = m.top.displaySlug
    ShowStatus("Loading " + SafeString(m.top.displayName, m.top.displaySlug) + "...")
    RequestCachedManifest()
    RequestManifest()
end sub

sub ResetPlayer()
    ResetPlayerState()
    m.activeDisplaySlug = ""
    ShowStatus("")
end sub

sub ResetPlayerState()
    m.slideTimer.control = "stop"
    m.refreshTimer.control = "stop"
    m.fadeAnimation.control = "stop"
    m.currentPoster.uri = ""
    m.nextPoster.uri = ""
    m.currentPoster.opacity = 0
    m.nextPoster.opacity = 0
    m.manifest = invalid
    m.manifestTask = invalid
    m.imageTask = invalid
    m.cacheTask = invalid
    m.cacheSaveTask = invalid
    m.pendingManifest = invalid
    m.slides = []
    m.currentIndex = 0
    m.nextIndex = 0
    m.paused = false
    m.refreshInFlight = false
    m.transitionPending = false
    m.loadingNext = false
    m.lastError = ""
    m.lastSuccess = ""
    m.networkMode = "live"
end sub

sub RequestManifest()
    if m.refreshInFlight then
        print "WordPress Foyer: manifest refresh skipped; request already running"
        return
    end if
    m.refreshInFlight = true
    task = CreateObject("roSGNode", "ManifestTask")
    task.mode = "manifest"
    task.apiBaseUrl = m.top.apiBaseUrl
    task.displaySlug = m.top.displaySlug
    task.ObserveField("result", "OnManifestResult")
    m.manifestTask = task
    task.control = "RUN"
end sub

sub OnManifestResult()
    if m.manifestTask = invalid then return
    m.refreshInFlight = false
    result = m.manifestTask.result
    if result = invalid then return

    if not result.ok
        m.lastError = SafeString(result.error, "manifest request failed")
        print "WordPress Foyer: manifest request failure "; m.lastError
        if m.manifest = invalid then ShowStatus("Could not load " + SafeString(m.top.displayName, m.top.displaySlug) + ". Retrying automatically.")
        UpdateDiagnostics()
        return
    end if

    incoming = result.manifest
    if incoming = invalid or incoming.display.slug <> m.activeDisplaySlug then
        print "WordPress Foyer: stale manifest ignored"
        return
    end if

    if m.manifest <> invalid and incoming.revision = m.manifest.revision
        print "WordPress Foyer: manifest revision unchanged"
        m.refreshTimer.duration = incoming.refreshSeconds
        m.refreshTimer.control = "start"
        m.lastSuccess = NowIsoString()
        UpdateDiagnostics()
        return
    end if

    print "WordPress Foyer: manifest revision changed "; incoming.revision
    m.pendingManifest = incoming
    PrepareManifestImages(incoming)
end sub

sub PrepareManifestImages(manifest as Object)
    if manifest = invalid or manifest.display.slug <> m.activeDisplaySlug then return
    task = CreateObject("roSGNode", "ImageDownloadTask")
    task.slides = manifest.slides
    task.ObserveField("result", "OnImagesReady")
    m.imageTask = task
    task.control = "RUN"
end sub

sub OnImagesReady()
    if m.imageTask = invalid then return
    result = m.imageTask.result
    if result = invalid then return
    if m.pendingManifest = invalid or m.pendingManifest.display.slug <> m.activeDisplaySlug then
        print "WordPress Foyer: stale image preparation ignored"
        return
    end if

    if not result.ok
        m.lastError = SafeString(result.error, "images unavailable")
        print "WordPress Foyer: image preparation failed "; m.lastError
        if m.manifest = invalid then ShowStatus("No usable images are available yet. Retrying automatically.")
        UpdateDiagnostics()
        return
    end if

    manifest = m.pendingManifest
    manifest.slides = result.readySlides
    AdoptManifest(manifest, "live")
end sub

sub AdoptManifest(manifest as Object, mode as String)
    m.manifest = manifest
    m.slides = manifest.slides
    m.currentIndex = 0
    m.nextIndex = 0
    m.networkMode = mode
    m.lastSuccess = NowIsoString()
    m.lastError = ""

    SaveCachedManifestAsync(manifest)
    m.refreshTimer.duration = manifest.refreshSeconds
    m.refreshTimer.control = "start"
    ShowSlide(0, true)
    UpdateDiagnostics()
end sub

sub ShowSlide(index as Integer, immediate as Boolean)
    if m.slides.Count() = 0 then
        ShowStatus("No slides available.")
        return
    end if

    slide = m.slides[index]
    m.statusGroup.visible = false

    targetPoster = m.nextPoster
    if immediate then targetPoster = m.currentPoster
    targetPoster.uri = slide.cachedUrl
    targetPoster.loadDisplayMode = PosterDisplayMode(slide.fit)

    if immediate
        m.currentIndex = index
        m.nextIndex = index
        m.loadingNext = false
        m.transitionPending = false
        m.currentPoster.opacity = 1
        m.nextPoster.opacity = 0
        ScheduleNextSlide(slide.durationSeconds)
    else
        m.nextIndex = index
        m.loadingNext = true
    end if
end sub

function PosterDisplayMode(fit as String) as String
    if fit = "contain" then return "scaleToFit"
    return "scaleToZoom"
end function

sub OnNextPosterLoadStatus()
    if not m.loadingNext then return
    if m.nextPoster.loadStatus <> "ready" then return
    m.loadingNext = false
    TransitionToNext()
end sub

sub TransitionToNext()
    if m.transitionPending then return
    if m.slides.Count() = 0 then return
    slide = m.slides[m.nextIndex]
    transitionDuration = slide.transitionDurationSeconds

    if m.manifest.channel.transition = "none" or transitionDuration <= 0
        SwapPosters()
    else
        m.transitionPending = true
        m.fadeAnimation.duration = transitionDuration
        m.fadeIn.fieldToInterp = m.nextPoster.id + ".opacity"
        m.fadeOut.fieldToInterp = m.currentPoster.id + ".opacity"
        m.fadeAnimation.control = "start"
        return
    end if

    ScheduleNextSlide(slide.durationSeconds)
end sub

sub OnFadeAnimationState()
    if m.fadeAnimation.state = "stopped" and m.transitionPending
        m.transitionPending = false
        if m.slides.Count() = 0 then return
        slide = m.slides[m.nextIndex]
        SwapPosters()
        ScheduleNextSlide(slide.durationSeconds)
    end if
end sub

sub SwapPosters()
    m.currentPoster.uri = m.nextPoster.uri
    m.currentPoster.loadDisplayMode = m.nextPoster.loadDisplayMode
    m.currentPoster.opacity = 1
    m.nextPoster.uri = ""
    m.nextPoster.opacity = 0
    m.currentIndex = m.nextIndex
end sub

sub ScheduleNextSlide(durationSeconds as Float)
    m.slideTimer.control = "stop"
    if m.paused or m.slides.Count() <= 1 then return
    m.slideTimer.duration = durationSeconds
    m.slideTimer.control = "start"
end sub

sub OnSlideTimer()
    if m.paused or m.transitionPending or m.loadingNext or m.slides.Count() <= 1 then return
    nextIndex = m.currentIndex + 1
    if nextIndex >= m.slides.Count() then nextIndex = 0
    ShowSlide(nextIndex, false)
end sub

sub OnRefreshTimer()
    RequestManifest()
end sub

sub ShowStatus(message as String)
    m.statusGroup.visible = true
    m.statusText.text = message
end sub

sub RequestCachedManifest()
    task = CreateObject("roSGNode", "CacheManifestTask")
    task.mode = "load"
    task.displaySlug = m.top.displaySlug
    task.ObserveField("result", "OnCachedManifestResult")
    m.cacheTask = task
    task.control = "RUN"
end sub

sub OnCachedManifestResult()
    if m.cacheTask = invalid then return
    result = m.cacheTask.result
    if result = invalid then return
    if result.ok and m.manifest = invalid
        if result.manifest = invalid or result.manifest.display.slug <> m.activeDisplaySlug then
            print "WordPress Foyer: stale cached manifest ignored"
            return
        end if
        print "WordPress Foyer: offline cache activated"
        AdoptManifest(result.manifest, "cached")
    else if not result.ok
        print "WordPress Foyer: cached manifest unavailable "; SafeString(result.error, "")
    end if
end sub

sub SaveCachedManifestAsync(manifest as Object)
    task = CreateObject("roSGNode", "CacheManifestTask")
    task.mode = "save"
    task.displaySlug = m.top.displaySlug
    task.manifest = manifest
    task.ObserveField("result", "OnCachedManifestSaved")
    m.cacheSaveTask = task
    task.control = "RUN"
end sub

sub OnCachedManifestSaved()
    if m.cacheSaveTask = invalid then return
    result = m.cacheSaveTask.result
    if result <> invalid and not result.ok
        print "WordPress Foyer: cached manifest save failed "; SafeString(result.error, "")
    end if
end sub

sub RefreshNow()
    RequestManifest()
end sub

sub TogglePause()
    m.paused = not m.paused
    if m.paused
        m.slideTimer.control = "stop"
    else if m.slides.Count() > 0
        ScheduleNextSlide(m.slides[m.currentIndex].durationSeconds)
    end if
end sub

sub UpdateDiagnostics()
    slideCount = 0
    revision = ""
    if m.manifest <> invalid
        slideCount = m.slides.Count()
        revision = m.manifest.revision
    end if
    m.top.diagnostics = {
        appVersion: m.config.appVersion,
        apiBaseUrl: m.top.apiBaseUrl,
        displaySlug: m.top.displaySlug,
        displayName: m.top.displayName,
        manifestRevision: revision,
        slideCount: slideCount,
        lastSuccess: m.lastSuccess,
        lastError: m.lastError,
        networkMode: m.networkMode
    }
end sub

function onKeyEvent(key as String, press as Boolean) as Boolean
    if not press then return false
    if key = "options"
        m.top.settingsRequested = true
        return true
    else if key = "play"
        TogglePause()
        return true
    else if key = "back"
        m.top.exitRequested = true
        return true
    else if key = "OK"
        return true
    end if
    return false
end function
