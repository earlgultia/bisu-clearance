Add-Type -AssemblyName System.Drawing

function New-RoundedRectanglePath {
    param(
        [float]$X,
        [float]$Y,
        [float]$Width,
        [float]$Height,
        [float]$Radius
    )

    $diameter = $Radius * 2
    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $path.AddArc($X, $Y, $diameter, $diameter, 180, 90)
    $path.AddArc($X + $Width - $diameter, $Y, $diameter, $diameter, 270, 90)
    $path.AddArc($X + $Width - $diameter, $Y + $Height - $diameter, $diameter, $diameter, 0, 90)
    $path.AddArc($X, $Y + $Height - $diameter, $diameter, $diameter, 90, 90)
    $path.CloseFigure()
    return $path
}

function New-Format {
    $format = New-Object System.Drawing.StringFormat
    $format.Alignment = [System.Drawing.StringAlignment]::Center
    $format.LineAlignment = [System.Drawing.StringAlignment]::Center
    return $format
}

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$masterPath = Join-Path $scriptDir "pwa-icon-master.png"
$svgPath = Join-Path $scriptDir "pwa-icon.svg"
$png512Path = Join-Path $scriptDir "pwa-icon-512.png"
$png192Path = Join-Path $scriptDir "pwa-icon-192.png"
$maskable512Path = Join-Path $scriptDir "pwa-icon-maskable-512.png"
$maskable192Path = Join-Path $scriptDir "pwa-icon-maskable-192.png"
$faviconPath = Join-Path $scriptDir "favicon.png"

$size = 1024
$bitmap = New-Object System.Drawing.Bitmap $size, $size
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$graphics.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
$graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$graphics.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
$graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$graphics.Clear([System.Drawing.Color]::Transparent)

$outerPath = New-RoundedRectanglePath 48 48 928 928 220
$bgBrush = New-Object System.Drawing.Drawing2D.LinearGradientBrush(
    ([System.Drawing.PointF]::new(0, 0)),
    ([System.Drawing.PointF]::new($size, $size)),
    ([System.Drawing.Color]::FromArgb(255, 46, 29, 94)),
    ([System.Drawing.Color]::FromArgb(255, 107, 75, 184))
)
$bgBlend = New-Object System.Drawing.Drawing2D.ColorBlend
$bgBlend.Colors = @(
    [System.Drawing.Color]::FromArgb(255, 36, 24, 78),
    [System.Drawing.Color]::FromArgb(255, 65, 40, 134),
    [System.Drawing.Color]::FromArgb(255, 95, 69, 170)
)
$bgBlend.Positions = @(0.0, 0.58, 1.0)
$bgBrush.InterpolationColors = $bgBlend
$graphics.FillPath($bgBrush, $outerPath)

$highlightPath = New-RoundedRectanglePath 90 86 844 400 180
$highlightBrush = New-Object System.Drawing.Drawing2D.LinearGradientBrush(
    ([System.Drawing.PointF]::new(120, 100)),
    ([System.Drawing.PointF]::new(120, 480)),
    ([System.Drawing.Color]::FromArgb(120, 255, 255, 255)),
    ([System.Drawing.Color]::FromArgb(0, 255, 255, 255))
)
$graphics.FillPath($highlightBrush, $highlightPath)

$goldPen = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(255, 240, 198, 99)), 18
$graphics.DrawPath($goldPen, $outerPath)

$innerPath = New-RoundedRectanglePath 112 112 800 800 180
$innerPen = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(90, 255, 255, 255)), 4
$graphics.DrawPath($innerPen, $innerPath)

$sealOuterBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 244, 197, 88))
$sealInnerBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 53, 34, 112))
$graphics.FillEllipse($sealOuterBrush, 262, 108, 500, 500)
$graphics.FillEllipse($sealInnerBrush, 298, 144, 428, 428)

$starBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 255, 231, 168))
for ($i = 0; $i -lt 5; $i++) {
    $angle = (-90 + ($i * 18)) * [Math]::PI / 180
    $x = 512 + [Math]::Cos($angle) * 150
    $y = 266 + [Math]::Sin($angle) * 150
    $graphics.FillEllipse($starBrush, $x - 12, $y - 12, 24, 24)
}

$shieldPath = New-Object System.Drawing.Drawing2D.GraphicsPath
$shieldPath.StartFigure()
$shieldPath.AddLine(512, 214, 626, 248)
$shieldPath.AddBezier(626, 248, 636, 356, 600, 430, 512, 484)
$shieldPath.AddBezier(512, 484, 424, 430, 388, 356, 398, 248)
$shieldPath.CloseFigure()

$shieldBrush = New-Object System.Drawing.Drawing2D.LinearGradientBrush(
    ([System.Drawing.PointF]::new(420, 220)),
    ([System.Drawing.PointF]::new(604, 470)),
    ([System.Drawing.Color]::FromArgb(255, 255, 255, 255)),
    ([System.Drawing.Color]::FromArgb(255, 228, 235, 255))
)
$graphics.FillPath($shieldBrush, $shieldPath)
$shieldPen = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(255, 240, 198, 99)), 12
$graphics.DrawPath($shieldPen, $shieldPath)

$docPen = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(255, 184, 190, 214)), 10
$docPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
$docPen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
$graphics.DrawLine($docPen, 470, 290, 575, 290)
$graphics.DrawLine($docPen, 470, 330, 575, 330)
$graphics.DrawLine($docPen, 470, 370, 545, 370)

$checkPen = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(255, 17, 157, 112)), 22
$checkPen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
$checkPen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
$checkPen.LineJoin = [System.Drawing.Drawing2D.LineJoin]::Round
$graphics.DrawLines($checkPen, @(
    [System.Drawing.Point]::new(438, 342),
    [System.Drawing.Point]::new(478, 382),
    [System.Drawing.Point]::new(584, 276)
))

$ribbonPath = New-RoundedRectanglePath 164 612 696 212 82
$ribbonBrush = New-Object System.Drawing.Drawing2D.LinearGradientBrush(
    ([System.Drawing.PointF]::new(164, 612)),
    ([System.Drawing.PointF]::new(164, 824)),
    ([System.Drawing.Color]::FromArgb(255, 27, 17, 61)),
    ([System.Drawing.Color]::FromArgb(255, 60, 38, 126))
)
$graphics.FillPath($ribbonBrush, $ribbonPath)
$ribbonPen = New-Object System.Drawing.Pen ([System.Drawing.Color]::FromArgb(255, 240, 198, 99)), 10
$graphics.DrawPath($ribbonPen, $ribbonPath)

$accentBrush = New-Object System.Drawing.Drawing2D.LinearGradientBrush(
    ([System.Drawing.PointF]::new(220, 798)),
    ([System.Drawing.PointF]::new(804, 798)),
    ([System.Drawing.Color]::FromArgb(255, 243, 183, 70)),
    ([System.Drawing.Color]::FromArgb(255, 255, 230, 164))
)
$graphics.FillRectangle($accentBrush, 228, 796, 568, 10)

$titleFormat = New-Format
$subtitleFormat = New-Format

$bisuFont = New-Object System.Drawing.Font("Segoe UI", 116, [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)
$clearanceFont = New-Object System.Drawing.Font("Segoe UI", 56, [System.Drawing.FontStyle]::Bold, [System.Drawing.GraphicsUnit]::Pixel)

$bisuShadowBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(110, 18, 9, 48))
$textWhiteBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 255, 255, 255))
$goldTextBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(255, 248, 223, 146))

$graphics.DrawString("BISU", $bisuFont, $bisuShadowBrush, ([System.Drawing.RectangleF]::new(164, 644, 696, 76)), $titleFormat)
$graphics.DrawString("BISU", $bisuFont, $textWhiteBrush, ([System.Drawing.RectangleF]::new(164, 636, 696, 76)), $titleFormat)
$graphics.DrawString("CLEARANCE", $clearanceFont, $bisuShadowBrush, ([System.Drawing.RectangleF]::new(164, 740, 696, 48)), $subtitleFormat)
$graphics.DrawString("CLEARANCE", $clearanceFont, $goldTextBrush, ([System.Drawing.RectangleF]::new(164, 734, 696, 48)), $subtitleFormat)

$bitmap.Save($masterPath, [System.Drawing.Imaging.ImageFormat]::Png)

function Save-ScaledPng {
    param(
        [string]$SourcePath,
        [string]$TargetPath,
        [int]$Dimension
    )

    $source = [System.Drawing.Image]::FromFile($SourcePath)
    $scaled = New-Object System.Drawing.Bitmap $Dimension, $Dimension
    $g = [System.Drawing.Graphics]::FromImage($scaled)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.Clear([System.Drawing.Color]::Transparent)
    $g.DrawImage($source, 0, 0, $Dimension, $Dimension)
    $scaled.Save($TargetPath, [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose()
    $scaled.Dispose()
    $source.Dispose()
}

Save-ScaledPng -SourcePath $masterPath -TargetPath $png512Path -Dimension 512
Save-ScaledPng -SourcePath $masterPath -TargetPath $png192Path -Dimension 192
Save-ScaledPng -SourcePath $masterPath -TargetPath $maskable512Path -Dimension 512
Save-ScaledPng -SourcePath $masterPath -TargetPath $maskable192Path -Dimension 192
Save-ScaledPng -SourcePath $masterPath -TargetPath $faviconPath -Dimension 64

$svgContent = @'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-label="BISU Clearance app icon">
  <image href="pwa-icon-512.png" width="512" height="512"/>
</svg>
'@
[System.IO.File]::WriteAllText($svgPath, $svgContent, [System.Text.Encoding]::UTF8)

$titleFormat.Dispose()
$subtitleFormat.Dispose()
$bisuFont.Dispose()
$clearanceFont.Dispose()
$bisuShadowBrush.Dispose()
$textWhiteBrush.Dispose()
$goldTextBrush.Dispose()
$accentBrush.Dispose()
$ribbonPen.Dispose()
$ribbonBrush.Dispose()
$checkPen.Dispose()
$docPen.Dispose()
$shieldPen.Dispose()
$shieldBrush.Dispose()
$shieldPath.Dispose()
$starBrush.Dispose()
$sealInnerBrush.Dispose()
$sealOuterBrush.Dispose()
$innerPen.Dispose()
$innerPath.Dispose()
$goldPen.Dispose()
$highlightBrush.Dispose()
$highlightPath.Dispose()
$bgBrush.Dispose()
$outerPath.Dispose()
$graphics.Dispose()
$bitmap.Dispose()
