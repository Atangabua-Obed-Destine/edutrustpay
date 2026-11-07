# Removes every task install-tasks.ps1 registered.
Get-ScheduledTask -TaskPath '\EdutrustPay\' -ErrorAction SilentlyContinue |
    Unregister-ScheduledTask -Confirm:$false
