sub Main()
    print "WordPress Foyer: app launch"

    screen = CreateObject("roSGScreen")
    port = CreateObject("roMessagePort")
    screen.SetMessagePort(port)

    screen.CreateScene("MainScene")
    screen.Show()

    while true
        msg = Wait(0, port)
        if type(msg) = "roSGScreenEvent"
            if msg.IsScreenClosed() then return
        end if
    end while
end sub
