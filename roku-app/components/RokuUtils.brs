function IsHttpUrl(value as Dynamic) as Boolean
    if type(value) <> "roString" and type(value) <> "String" then return false
    lower = LCase(value)
    return Left(lower, 7) = "http://" or Left(lower, 8) = "https://"
end function

function ClampNumber(value as Dynamic, fallback as Float, minValue as Float, maxValue as Float) as Float
    numberValue = fallback
    valueType = type(value)
    if valueType = "roInt" or valueType = "Integer" or valueType = "roFloat" or valueType = "Float" or valueType = "Double" then
        numberValue = value
    else if valueType = "roString" or valueType = "String" then
        parsed = Val(value)
        if parsed <> 0 or value = "0" then numberValue = parsed
    end if

    if numberValue < minValue then return minValue
    if numberValue > maxValue then return maxValue
    return numberValue
end function

function SafeString(value as Dynamic, fallback as String) as String
    if type(value) = "roString" or type(value) = "String" then return value
    valueType = type(value)
    if valueType = "roInt" or valueType = "Integer" or valueType = "roFloat" or valueType = "Float" or valueType = "Double" then return value.ToStr()
    return fallback
end function

function MakeUrl(baseUrl as String, path as String) as String
    base = baseUrl
    if Right(base, 1) = "/" then base = Left(base, Len(base) - 1)
    return base + path
end function

function AddCacheBuster(url as String, revision as String) as String
    separator = "?"
    if Instr(1, url, "?") > 0 then separator = "&"
    return url + separator + "v=" + SanitizeFilePart(revision)
end function

function SanitizeFilePart(value as Dynamic) as String
    text = SafeString(value, "")
    clean = ""
    allowed = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_"

    for i = 1 to Len(text)
        ch = Mid(text, i, 1)
        if Instr(1, allowed, ch) > 0 then
            clean = clean + ch
        end if
    end for

    if clean = "" then clean = "x"
    return clean
end function

function SlideCachePath(slide as Object, cacheDir as String) as String
    idPart = SanitizeFilePart(slide.id.ToStr())
    revisionPart = SanitizeFilePart(Left(slide.revision, 32))
    return cacheDir + "/slide-" + idPart + "-" + revisionPart + ".png"
end function

function EnsureCacheDir(cacheDir as String) as Boolean
    fs = CreateObject("roFileSystem")
    if fs.Exists(cacheDir) then return true
    return fs.CreateDirectory(cacheDir)
end function

function ReadTextFile(path as String) as Dynamic
    fs = CreateObject("roFileSystem")
    if not fs.Exists(path) then return invalid
    return ReadAsciiFile(path)
end function

function WriteTextFile(path as String, value as String) as Boolean
    return WriteAsciiFile(path, value)
end function

function NowIsoString() as String
    dt = CreateObject("roDateTime")
    dt.ToLocalTime()
    return dt.ToISOString()
end function
