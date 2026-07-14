function RegistryReadSettings() as Object
    config = AppConfig()
    section = CreateObject("roRegistrySection", config.registrySection)
    apiBaseUrl = section.Read("apiBaseUrl")
    if apiBaseUrl = invalid or apiBaseUrl = "" then apiBaseUrl = config.defaultApiBaseUrl

    return {
        apiBaseUrl: apiBaseUrl,
        displaySlug: section.Read("displaySlug"),
        displayName: section.Read("displayName")
    }
end function

function RegistrySaveDisplay(slug as String, name as String, apiBaseUrl as Dynamic) as Boolean
    config = AppConfig()
    section = CreateObject("roRegistrySection", config.registrySection)

    if apiBaseUrl = invalid or apiBaseUrl = "" then apiBaseUrl = config.defaultApiBaseUrl
    section.Write("apiBaseUrl", apiBaseUrl)
    section.Write("displaySlug", slug)
    section.Write("displayName", name)
    section.Flush()

    print "WordPress Foyer: saved display loaded slug="; slug
    return true
end function

function RegistryClearDisplay() as Boolean
    config = AppConfig()
    section = CreateObject("roRegistrySection", config.registrySection)
    section.Delete("displaySlug")
    section.Delete("displayName")
    section.Flush()
    return true
end function
