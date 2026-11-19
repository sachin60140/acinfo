{{--
    Stickers for files: one 4 x 6 inch label each, for the thermal printer at
    the counter. See WorkFileController::stickers() and WorkFileModel::sticker().

    A page of its own rather than the admin layout: what prints is the labels
    and nothing else, each exactly one page of the label's size. Black on white
    and nothing grey, because a thermal printer has one colour and dithers the
    rest into dots.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ count($stickers) === 1 ? 'Sticker '.$stickers[0]['file_no'] : count($stickers).' Stickers' }} | Ac Info</title>
    <style>
        /* The label is the page: no margin, so the driver's own is the only one. */
        @page {
            size: 4in 6in;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            background: #e9ecef;
            color: #000;
            font-family: Arial, Helvetica, sans-serif;
            margin: 0;
            padding: 0;
        }

        /* ---- On screen: what will print, and how to print it -------------- */

        .tools {
            align-items: center;
            background: #fff;
            border-bottom: 1px solid #ced4da;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .tools__say {
            font-size: 14px;
            line-height: 1.4;
            max-width: 40rem;
        }

        .tools__actions {
            display: flex;
            gap: 0.5rem;
        }

        .tools a,
        .tools button {
            align-items: center;
            border: 1px solid #4154f1;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            font: inherit;
            font-size: 15px;
            font-weight: 700;
            justify-content: center;
            min-height: 44px;
            padding: 0 1rem;
            text-decoration: none;
        }

        .tools button {
            background: #4154f1;
            color: #fff;
        }

        .tools a {
            background: #fff;
            color: #4154f1;
        }

        .sheet {
            align-items: center;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            padding: 1rem;
        }

        /* ---- The sticker ---------------------------------------------------- */

        .sticker {
            background: #fff;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
            height: 6in;
            overflow: hidden;
            padding: 0.14in 0.16in;
            width: 4in;
        }

        .st-head {
            align-items: baseline;
            border-bottom: 2.5pt solid #000;
            display: flex;
            justify-content: space-between;
            padding-bottom: 3pt;
        }

        .st-file {
            font-size: 16pt;
            font-weight: 700;
        }

        .st-date {
            font-size: 10pt;
        }

        .st-row {
            border-bottom: 1pt solid #000;
            padding: 4pt 0;
        }

        .st-label {
            display: block;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .st-value {
            display: block;
            font-size: 12pt;
            font-weight: 700;
            line-height: 1.2;
            overflow-wrap: anywhere;
        }

        .st-customer {
            font-size: 14pt;
        }

        .st-mobile {
            font-size: 12pt;
            font-weight: 400;
            letter-spacing: 0.03em;
        }

        /* The plate, in the shape a plate is read in. */
        .st-plate {
            border: 2pt solid #000;
            display: block;
            font-size: 24pt;
            font-weight: 700;
            letter-spacing: 0.06em;
            margin-top: 2pt;
            padding: 2pt 0;
            text-align: center;
        }

        /* Two facts side by side: a name and its mobile. A flex row, not a
           grid: the label is the same size on every screen, so there is no
           narrow layout for it to fall back to. */
        .st-pair {
            align-items: baseline;
            display: flex;
            gap: 8pt;
        }

        .st-pair > :first-child {
            flex: 1 1 auto;
            min-width: 0;
        }

        .st-pair > :last-child {
            flex: none;
        }

        /* Asked for and not typed: a line to write it on. */
        .st-blank {
            border-bottom: 1pt solid #000;
            display: block;
            height: 15pt;
        }

        .st-pair .st-blank {
            min-width: 1.2in;
        }

        .st-works {
            border-bottom: 0;
            flex: 1;
        }

        .st-work-list {
            font-size: 13pt;
            font-weight: 700;
            line-height: 1.25;
        }

        .st-details {
            font-size: 10pt;
            line-height: 1.3;
            margin-top: 3pt;
            overflow-wrap: anywhere;
        }

        /* A phone narrower than the label sees it smaller, rather than a page
           to scroll sideways; what prints is the label at its own size. */
        @media screen and (max-width: 26rem) {
            .sheet {
                padding: 0.5rem 0;
                zoom: 0.85;
            }
        }

        /* ---- Printing: the stickers alone, a page each --------------------- */

        @media print {
            html,
            body {
                background: #fff;
            }

            .tools {
                display: none;
            }

            .sheet {
                display: block;
                padding: 0;
            }

            .sticker {
                box-shadow: none;
                break-after: page;
                page-break-after: always;
            }

            .sticker:last-child {
                break-after: auto;
                page-break-after: auto;
            }
        }
    </style>
</head>
<body>
    <div class="tools">
        <div class="tools__say">
            <strong>{{ count($stickers) === 1 ? 'One sticker' : count($stickers).' stickers' }}, 4 x 6 inch.</strong>
            Print to the thermal printer with paper size <strong>4 x 6 in (100 x 150 mm)</strong>,
            margins <strong>None</strong> and scale <strong>100%</strong>.
        </div>
        <div class="tools__actions">
            <a href="{{ route('workfile.index') }}">Work Files</a>
            <button type="button" onclick="window.print()">Print</button>
        </div>
    </div>

    <div class="sheet">
        @foreach ($stickers as $sticker)
            <section class="sticker" aria-label="Sticker for {{ $sticker['file_no'] }}">
                <div class="st-head">
                    <span class="st-file">{{ $sticker['file_no'] }}</span>
                    @if ($sticker['received'])
                        <span class="st-date">Received {{ $sticker['received'] }}</span>
                    @endif
                </div>

                <div class="st-row">
                    <span class="st-label">Customer</span>
                    <span class="st-value st-customer">{{ $sticker['customer'] }}</span>
                    <span class="st-value st-mobile">{{ $sticker['customer_mobile'] }}</span>
                </div>

                <div class="st-row">
                    <span class="st-label">Registration No.</span>
                    @if ($sticker['registration'] !== '')
                        <span class="st-plate">{{ $sticker['registration'] }}</span>
                    @else
                        <span class="st-blank"></span>
                    @endif
                </div>

                <div class="st-row">
                    <span class="st-label">Owner's Mobile</span>
                    @if ($sticker['owner_mobile'])
                        <span class="st-value st-mobile">{{ $sticker['owner_mobile'] }}</span>
                    @else
                        <span class="st-blank"></span>
                    @endif
                </div>

                @if ($sticker['lender'])
                    <div class="st-row">
                        <span class="st-label">Lender &middot; {{ $sticker['lender']['for'] }}</span>
                        @if ($sticker['lender']['name'])
                            <span class="st-value">{{ $sticker['lender']['name'] }}</span>
                        @else
                            <span class="st-blank"></span>
                        @endif
                    </div>
                @endif

                @if ($sticker['financer'])
                    <div class="st-row">
                        <span class="st-label">Financer &middot; {{ $sticker['financer']['for'] }}</span>
                        @if ($sticker['financer']['name'])
                            <span class="st-value">{{ $sticker['financer']['name'] }}</span>
                        @else
                            <span class="st-blank"></span>
                        @endif
                    </div>
                @endif

                @if ($sticker['parties'])
                    @foreach (['seller' => 'Seller', 'buyer' => 'Buyer'] as $side => $title)
                        <div class="st-row">
                            <span class="st-label">{{ $title }} &middot; {{ $sticker['parties']['for'] }}</span>
                            <div class="st-pair">
                                @if ($sticker['parties'][$side])
                                    <span class="st-value">{{ $sticker['parties'][$side] }}</span>
                                @else
                                    <span class="st-blank"></span>
                                @endif
                                @if ($sticker['parties'][$side.'_mobile'])
                                    <span class="st-value st-mobile">{{ $sticker['parties'][$side.'_mobile'] }}</span>
                                @else
                                    <span class="st-blank"></span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endif

                <div class="st-row st-works">
                    <span class="st-label">Work</span>
                    <div class="st-work-list">{{ implode(' · ', $sticker['works']) }}</div>
                    @if ($sticker['details'])
                        <div class="st-details">{{ $sticker['details'] }}</div>
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    @if ($autoPrint)
        {{-- Opened to print: straight to the dialog, the stickers drawn first. --}}
        <script>
            window.addEventListener('load', () => window.print());
        </script>
    @endif
</body>
</html>
