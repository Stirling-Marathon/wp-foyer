sub init()
    m.top.focusable = true
    m.list = m.top.FindNode("displayList")
    m.status = m.top.FindNode("status")
    m.displays = []
    m.list.ObserveField("itemSelected", "OnItemSelected")
    m.list.SetFocus(true)
end sub

sub OnDisplaysChanged()
    displays = m.top.displays
    if type(displays) <> "roArray" then displays = []
    m.displays = displays

    content = CreateObject("roSGNode", "ContentNode")
    for each display in displays
        item = content.CreateChild("ContentNode")
        item.title = display.name
        item.description = display.slug
    end for

    m.list.content = content
    if displays.Count() > 0 then
        m.list.SetFocus(true)
        m.status.text = "Use OK to assign this Roku."
    end if
end sub

sub OnStatusChanged()
    m.status.text = m.top.statusText
end sub

sub OnItemSelected()
    index = m.list.itemSelected
    if index >= 0 and index < m.displays.Count()
        m.top.selectedDisplay = m.displays[index]
    end if
end sub

function onKeyEvent(key as String, press as Boolean) as Boolean
    if not press then return false
    if key = "replay"
        m.top.retryRequested = true
        return true
    end if
    return false
end function
