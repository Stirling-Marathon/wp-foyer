' Lightweight fixture-oriented BrightScript checks for sideload/debug use.
' Copy or include this file in a temporary test scene if deeper device-side checks are needed.

sub RunLogicTests()
    print "WordPress Foyer tests: start"
    AssertTrue(IsHttpUrl("http://example.test/a.png"), "http URL accepted")
    AssertTrue(IsHttpUrl("https://example.test/a.png"), "https URL accepted")
    AssertFalse(IsHttpUrl("file:///tmp/a.png"), "file URL rejected")
    AssertTrue(AddCacheBuster("http://x/a.png", "rev") = "http://x/a.png?v=rev", "cache buster no query")
    AssertTrue(AddCacheBuster("http://x/a.png?a=1", "rev") = "http://x/a.png?a=1&v=rev", "cache buster with query")

    displayList = ParseJson(ReadAsciiFile("pkg:/tests/fixtures/display-list.json"))
    displays = NormalizeDisplayList(displayList)
    AssertTrue(displays.ok, "display list accepted")
    AssertTrue(displays.displays.Count() = 5, "display list count preserved")

    manifest = ParseJson(ReadAsciiFile("pkg:/tests/fixtures/normal-two-image-display.json"))
    normalized = NormalizeManifest(manifest, "office-upstairs")
    AssertTrue(normalized.ok, "normal manifest accepted")
    AssertTrue(normalized.manifest.slides.Count() = 2, "slide count preserved")
    AssertTrue(normalized.manifest.slides[0].id = 101, "slide order preserved")
    AssertTrue(normalized.manifest.channel.durationSeconds = 8, "channel duration preserved")

    bad = ParseJson(ReadAsciiFile("pkg:/tests/fixtures/failed-image-url.json"))
    badResult = NormalizeManifest(bad, "bad-image")
    AssertFalse(badResult.ok, "invalid URL rejected")

    unsupported = ParseJson(ReadAsciiFile("pkg:/tests/fixtures/unsupported-slide.json"))
    unsupportedResult = NormalizeManifest(unsupported, "mixed")
    AssertTrue(unsupportedResult.ok, "unsupported slide skipped when playable slide exists")
    AssertTrue(unsupportedResult.manifest.slides.Count() = 1, "unsupported slide omitted")
    print "WordPress Foyer tests: complete"
end sub

sub AssertTrue(value as Boolean, message as String)
    if value then
        print "PASS "; message
    else
        print "FAIL "; message
    end if
end sub

sub AssertFalse(value as Boolean, message as String)
    AssertTrue(not value, message)
end sub
