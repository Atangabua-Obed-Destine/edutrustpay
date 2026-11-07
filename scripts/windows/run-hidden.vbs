' Runs one artisan command with no window, so a scheduled task does not flash a
' console on the screen every minute. Output goes to storage\logs\scheduled-tasks.log.
' Usage: wscript run-hidden.vbs <app folder> <php.exe> <artisan arguments>
Set args = WScript.Arguments
Set shell = CreateObject("WScript.Shell")
shell.CurrentDirectory = args(0)
line = "cmd /c """"" & args(1) & """ artisan " & args(2) & " >> storage\logs\scheduled-tasks.log 2>&1"""
WScript.Quit shell.Run(line, 0, True)
