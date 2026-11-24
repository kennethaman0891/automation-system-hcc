# True only for a Windows PE executable. A copied macOS Mach-O binary is not one.
function Test-HccWindowsExecutable {
    param([Parameter(Mandatory = $true)][string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) {
        return $false
    }
    $stream = $null
    try {
        $stream = New-Object System.IO.FileStream(
            $Path,
            [System.IO.FileMode]::Open,
            [System.IO.FileAccess]::Read,
            [System.IO.FileShare]::ReadWrite
        )
        $b0 = $stream.ReadByte()
        $b1 = $stream.ReadByte()
        return ($b0 -eq 0x4D -and $b1 -eq 0x5A)
    } catch {
        return $false
    } finally {
        if ($null -ne $stream) {
            $stream.Dispose()
        }
    }
}
