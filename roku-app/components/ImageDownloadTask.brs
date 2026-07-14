sub init()
    m.top.functionName = "RunImageDownloadTask"
end sub

sub RunImageDownloadTask()
    config = AppConfig()
    result = { ok: false, readySlides: [], failed: [], error: "" }
    slides = m.top.slides
    if type(slides) <> "roArray" then
        result.error = "missing slides"
        m.top.result = result
        return
    end if

    if not EnsureCacheDir(config.cacheDir) then
        result.error = "cache unavailable"
        m.top.result = result
        return
    end if

    fs = CreateObject("roFileSystem")
    ready = []
    failed = []

    for each slide in slides
        cachePath = SafeString(slide.cachePath, "")
        if cachePath = "" then
            failed.Push({ id: slide.id, error: "missing cache path" })
        else if fs.Exists(cachePath)
            print "WordPress Foyer: image cache hit slide="; slide.id
            slide.cachedUrl = cachePath
            ready.Push(slide)
        else
            print "WordPress Foyer: image cache miss slide="; slide.id
            downloadUrl = AddCacheBuster(slide.url, slide.revision)
            tempPath = config.cacheDir + "/tmp-" + slide.id.ToStr() + "-" + SanitizeFilePart(Left(slide.revision, 12)) + ".part"
            if fs.Exists(tempPath) then fs.Delete(tempPath)

            ok = DownloadImage(downloadUrl, tempPath, cachePath, config.imageDownloadTimeoutSeconds)
            if ok
                print "WordPress Foyer: image download success slide="; slide.id
                slide.cachedUrl = cachePath
                ready.Push(slide)
            else
                print "WordPress Foyer: image download failure slide="; slide.id
                if fs.Exists(tempPath) then fs.Delete(tempPath)
                failed.Push({ id: slide.id, error: "download failed" })
            end if
        end if
    end for

    CleanupCache(config.cacheDir, config.maxCacheFiles)
    result.ok = ready.Count() > 0
    result.readySlides = ready
    result.failed = failed
    if not result.ok then result.error = "no images ready"
    m.top.result = result
end sub

function DownloadImage(url as String, tempPath as String, finalPath as String, timeoutSeconds as Integer) as Boolean
    if not IsHttpUrl(url) then return false

    fs = CreateObject("roFileSystem")
    port = CreateObject("roMessagePort")
    transfer = CreateObject("roUrlTransfer")
    transfer.SetUrl(url)
    transfer.SetRequest("GET")
    transfer.SetMessagePort(port)
    transfer.SetCertificatesFile("common:/certs/ca-bundle.crt")
    transfer.InitClientCertificates()
    transfer.SetMinimumTransferRate(1, timeoutSeconds)

    if not transfer.AsyncGetToFile(tempPath) then
        if fs.Exists(tempPath) then fs.Delete(tempPath)
        return false
    end if

    msg = Wait(timeoutSeconds * 1000, port)
    if msg = invalid then
        transfer.AsyncCancel()
        if fs.Exists(tempPath) then fs.Delete(tempPath)
        return false
    end if
    if type(msg) <> "roUrlEvent" then
        transfer.AsyncCancel()
        if fs.Exists(tempPath) then fs.Delete(tempPath)
        return false
    end if

    status = msg.GetResponseCode()
    if status < 200 or status > 299 then
        if fs.Exists(tempPath) then fs.Delete(tempPath)
        return false
    end if

    if fs.Exists(finalPath) then fs.Delete(finalPath)
    return fs.Rename(tempPath, finalPath)
end function

sub CleanupCache(cacheDir as String, maxFiles as Integer)
    fs = CreateObject("roFileSystem")
    files = fs.GetDirectoryListing(cacheDir)
    if files = invalid or files.Count() <= maxFiles then return

    removed = 0
    for each fileName in files
        if removed >= files.Count() - maxFiles then exit for
        if Left(fileName, 6) = "slide-"
            fs.Delete(cacheDir + "/" + fileName)
            removed = removed + 1
        end if
    end for
end sub
