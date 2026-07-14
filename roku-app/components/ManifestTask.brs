sub init()
    m.top.functionName = "RunManifestTask"
end sub

sub RunManifestTask()
    config = AppConfig()
    mode = m.top.mode
    baseUrl = m.top.apiBaseUrl
    if baseUrl = invalid or baseUrl = "" then baseUrl = config.defaultApiBaseUrl

    if not IsHttpUrl(baseUrl) then
        m.top.result = { ok: false, mode: mode, status: 0, error: "invalid API URL" }
        return
    end if

    if mode = "displays"
        url = MakeUrl(baseUrl, "/displays")
    else
        slug = SafeString(m.top.displaySlug, "")
        if slug = "" then
            m.top.result = { ok: false, mode: mode, status: 0, error: "missing display slug" }
            return
        end if
        url = MakeUrl(baseUrl, "/displays/" + SanitizeFilePart(slug))
    end if

    print "WordPress Foyer: "; mode; " request start"
    response = HttpGetJson(url, config.requestTimeoutSeconds)
    response.mode = mode
    if not response.ok then
        print "WordPress Foyer: "; mode; " request failure "; response.error
        m.top.result = response
        return
    end if

    if mode = "displays"
        normalized = NormalizeDisplayList(response.json)
    else
        normalized = NormalizeManifest(response.json, m.top.displaySlug)
    end if

    if not normalized.ok then
        print "WordPress Foyer: "; mode; " validation failure "; normalized.error
        m.top.result = { ok: false, mode: mode, status: response.status, error: normalized.error }
        return
    end if

    print "WordPress Foyer: "; mode; " request success"
    if mode = "displays"
        m.top.result = { ok: true, mode: mode, displays: normalized.displays, status: response.status }
    else
        m.top.result = { ok: true, mode: mode, manifest: normalized.manifest, status: response.status }
    end if
end sub

function HttpGetJson(url as String, timeoutSeconds as Integer) as Object
    port = CreateObject("roMessagePort")
    transfer = CreateObject("roUrlTransfer")
    transfer.SetUrl(url)
    transfer.SetRequest("GET")
    transfer.SetMessagePort(port)
    transfer.SetCertificatesFile("common:/certs/ca-bundle.crt")
    transfer.InitClientCertificates()
    transfer.RetainBodyOnError(true)
    transfer.SetMinimumTransferRate(1, timeoutSeconds)

    if not transfer.AsyncGetToString() then
        return { ok: false, status: 0, error: "request could not start" }
    end if

    msg = Wait(timeoutSeconds * 1000, port)
    if msg = invalid then
        transfer.AsyncCancel()
        return { ok: false, status: 0, error: "request timed out" }
    end if
    if type(msg) <> "roUrlEvent" then
        transfer.AsyncCancel()
        return { ok: false, status: 0, error: "unexpected network event" }
    end if

    status = msg.GetResponseCode()
    body = msg.GetString()
    if status < 200 or status > 299 then
        return { ok: false, status: status, error: "HTTP " + status.ToStr() }
    end if
    if body = invalid or body = "" then
        return { ok: false, status: status, error: "empty response" }
    end if

    json = ParseJson(body)
    if json = invalid then
        return { ok: false, status: status, error: "malformed JSON" }
    end if

    return { ok: true, status: status, json: json }
end function
