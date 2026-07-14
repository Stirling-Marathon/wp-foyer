sub init()
    m.config = AppConfig()
    m.selector = m.top.FindNode("selector")
    m.player = m.top.FindNode("player")
    m.menuOverlay = m.top.FindNode("menuOverlay")
    m.menuSubtitle = m.top.FindNode("menuSubtitle")
    m.menuList = m.top.FindNode("menuList")

    m.selector.ObserveField("selectedDisplay", "OnDisplaySelected")
    m.selector.ObserveField("retryRequested", "OnSelectorRetry")
    m.player.ObserveField("settingsRequested", "OpenSettings")
    m.player.ObserveField("refreshRequested", "OnRefreshRequested")
    m.player.ObserveField("exitRequested", "OpenBackNotice")
    m.menuList.ObserveField("itemSelected", "OnMenuSelected")

    m.settings = RegistryReadSettings()
    if m.settings.apiBaseUrl = invalid or m.settings.apiBaseUrl = ""
        ShowSelector("Configuration error: API base URL is missing. Rebuild the Roku package with config.local.json.")
    else if m.settings.displaySlug <> invalid and m.settings.displaySlug <> ""
        StartPlayer(m.settings.displaySlug, SafeString(m.settings.displayName, m.settings.displaySlug))
    else
        ShowSelector("Loading displays...")
        FetchDisplays()
    end if
end sub

sub ShowSelector(status as String)
    m.menuOverlay.visible = false
    m.player.CallFunc("ResetPlayer")
    m.player.visible = false
    m.selector.visible = true
    m.selector.statusText = status
    m.selector.SetFocus(true)
end sub

sub FetchDisplays()
    task = CreateObject("roSGNode", "ManifestTask")
    task.mode = "displays"
    task.apiBaseUrl = m.settings.apiBaseUrl
    task.ObserveField("result", "OnDisplaysResult")
    m.displayTask = task
    task.control = "RUN"
end sub

sub OnDisplaysResult()
    result = m.displayTask.result
    if result <> invalid and result.ok
        m.selector.displays = result.displays
    else
        message = "Display list could not be loaded. Press replay to retry."
        if result <> invalid then message = message + " " + SafeString(result.error, "")
        m.selector.statusText = message
    end if
end sub

sub OnSelectorRetry()
    m.selector.statusText = "Retrying..."
    FetchDisplays()
end sub

sub OnDisplaySelected()
    display = m.selector.selectedDisplay
    if display = invalid then return
    RegistrySaveDisplay(display.slug, display.name, m.settings.apiBaseUrl)
    m.settings.displaySlug = display.slug
    m.settings.displayName = display.name
    StartPlayer(display.slug, display.name)
end sub

sub StartPlayer(slug as String, name as String)
    m.menuOverlay.visible = false
    m.selector.visible = false
    m.player.visible = true
    m.player.displaySlug = slug
    m.player.displayName = name
    m.player.apiBaseUrl = m.settings.apiBaseUrl
    m.player.SetFocus(true)
    m.player.CallFunc("StartPlayer")
end sub

sub OpenSettings()
    ShowMenu("Current display: " + SafeString(m.settings.displayName, m.settings.displaySlug), ["Change display", "Refresh now", "Diagnostics", "Resume signage"], "settings")
end sub

sub OpenBackNotice()
    ShowMenu("Press Home on the Roku remote to leave the app.", ["Change display", "Resume signage"], "back")
end sub

sub ShowMenu(subtitle as String, items as Object, mode as String)
    m.menuMode = mode
    m.menuSubtitle.text = subtitle
    content = CreateObject("roSGNode", "ContentNode")
    for each title in items
        item = content.CreateChild("ContentNode")
        item.title = title
    end for
    m.menuList.content = content
    m.menuOverlay.visible = true
    m.menuList.SetFocus(true)
end sub

sub OnMenuSelected()
    index = m.menuList.itemSelected
    if m.menuMode = "settings"
        if index = 0
            ShowSelector("Loading displays...")
            FetchDisplays()
        else if index = 1
            m.menuOverlay.visible = false
            m.player.SetFocus(true)
            m.player.CallFunc("RefreshNow")
        else if index = 2
            ShowDiagnostics()
        else
            m.menuOverlay.visible = false
            m.player.SetFocus(true)
        end if
    else if m.menuMode = "back"
        if index = 0
            ShowSelector("Loading displays...")
            FetchDisplays()
        else
            m.menuOverlay.visible = false
            m.player.SetFocus(true)
        end if
    else if m.menuMode = "diagnostics"
        m.menuOverlay.visible = false
        m.player.SetFocus(true)
    end if
end sub

sub ShowDiagnostics()
    d = m.player.diagnostics
    if d = invalid then d = {}
    text = "Version: " + SafeString(d.appVersion, m.config.appVersion)
    text = text + Chr(10) + "API: " + SafeString(d.apiBaseUrl, m.settings.apiBaseUrl)
    text = text + Chr(10) + "Display: " + SafeString(d.displaySlug, "")
    text = text + Chr(10) + "Revision: " + SafeString(d.manifestRevision, "")
    text = text + Chr(10) + "Slides: " + SafeString(d.slideCount, "0")
    text = text + Chr(10) + "Mode: " + SafeString(d.networkMode, "")
    text = text + Chr(10) + "Last success: " + SafeString(d.lastSuccess, "")
    text = text + Chr(10) + "Last error: " + SafeString(d.lastError, "")
    ShowMenu(text, ["Resume signage"], "diagnostics")
end sub

function onKeyEvent(key as String, press as Boolean) as Boolean
    if not press then return false
    if m.menuOverlay.visible
        if key = "back" or key = "options"
            m.menuOverlay.visible = false
            if m.player.visible then m.player.SetFocus(true)
            if m.selector.visible then m.selector.SetFocus(true)
            return true
        end if
    end if
    return false
end function
