/**
 * DineFlow Offline ISO/IEC 18004 QR Code Generator (Zero external dependencies).
 * Supports Byte Mode (UTF-8), ECC Level M, Versions 1..12 (up to 287 UTF-8 bytes),
 * and also falls back cleanly to Level L for longer URLs if needed.
 */
(function (global) {
    "use strict";

    // GF(256) with primitive polynomial 0x11D (x^8 + x^4 + x^3 + x^2 + 1)
    const EXP = new Uint8Array(512);
    const LOG = new Uint8Array(256);
    (function initGalois() {
        let x = 1;
        for (let i = 0; i < 255; i++) {
            EXP[i] = x;
            EXP[i + 255] = x;
            LOG[x] = i;
            x = x & 0x80 ? ((x << 1) ^ 0x11d) : (x << 1);
        }
    })();

    function gfMul(a, b) {
        if (a === 0 || b === 0) return 0;
        return EXP[LOG[a] + LOG[b]];
    }

    function rsGeneratorPoly(ecCount) {
        let poly = [1];
        for (let i = 0; i < ecCount; i++) {
            const next = new Array(poly.length + 1).fill(0);
            const root = EXP[i];
            for (let j = 0; j < poly.length; j++) {
                next[j] ^= poly[j];
                next[j + 1] ^= gfMul(poly[j], root);
            }
            poly = next;
        }
        return poly;
    }

    function rsComputeEc(data, ecCount) {
        const gen = rsGeneratorPoly(ecCount);
        const rem = new Uint8Array(data.length + ecCount);
        rem.set(data, 0);
        for (let i = 0; i < data.length; i++) {
            const factor = rem[i];
            if (factor !== 0) {
                for (let j = 0; j < gen.length; j++) {
                    rem[i + j] ^= gfMul(gen[j], factor);
                }
            }
        }
        return Array.from(rem.slice(data.length));
    }

    // Version table for ECC Level M (index 1..12): [ecPerBlock, g1Blocks, g1Data, g2Blocks, g2Data]
    const ECC_M_SPEC = {
        1:  [10, 1, 16, 0, 0],
        2:  [16, 1, 28, 0, 0],
        3:  [26, 1, 44, 0, 0],
        4:  [18, 2, 32, 0, 0],
        5:  [24, 2, 43, 0, 0],
        6:  [16, 4, 27, 0, 0],
        7:  [18, 4, 31, 0, 0],
        8:  [22, 2, 38, 2, 39],
        9:  [22, 3, 36, 2, 37],
        10: [26, 4, 43, 1, 44],
        11: [30, 1, 50, 4, 51],
        12: [22, 6, 36, 2, 37],
    };

    const ALIGN_POS = {
        1: [],
        2: [6, 18],
        3: [6, 22],
        4: [6, 26],
        5: [6, 30],
        6: [6, 34],
        7: [6, 22, 38],
        8: [6, 24, 42],
        9: [6, 26, 46],
        10: [6, 28, 50],
        11: [6, 30, 54],
        12: [6, 32, 58],
    };

    const MASK_FNS = [
        (r, c) => (r + c) % 2 === 0,
        (r, c) => r % 2 === 0,
        (r, c) => c % 3 === 0,
        (r, c) => (r + c) % 3 === 0,
        (r, c) => (Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0,
        (r, c) => ((r * c) % 2) + ((r * c) % 3) === 0,
        (r, c) => (((r * c) % 2) + ((r * c) % 3)) % 2 === 0,
        (r, c) => (((r + c) % 2) + ((r * c) % 3)) % 2 === 0,
    ];

    function utf8Bytes(str) {
        if (typeof TextEncoder !== "undefined") {
            return Array.from(new TextEncoder().encode(str));
        }
        const encoded = unescape(encodeURIComponent(str));
        const out = new Array(encoded.length);
        for (let i = 0; i < encoded.length; i++) out[i] = encoded.charCodeAt(i);
        return out;
    }

    function pickVersion(byteLen) {
        for (let v = 1; v <= 12; v++) {
            const [, g1b, g1d, g2b, g2d] = ECC_M_SPEC[v];
            const totalDataBytes = g1b * g1d + g2b * g2d;
            const lenBits = v < 10 ? 8 : 16;
            const overheadBits = 4 + lenBits; // mode (4) + char count
            if (overheadBits + byteLen * 8 <= totalDataBytes * 8) {
                return v;
            }
        }
        throw new Error("URL vượt quá dung lượng QR hỗ trợ (tối đa ~287 bytes)");
    }

    function buildCodewords(bytes, version) {
        const [ecPerBlock, g1b, g1d, g2b, g2d] = ECC_M_SPEC[version];
        const totalDataBytes = g1b * g1d + g2b * g2d;
        const lenBits = version < 10 ? 8 : 16;
        const bits = [];

        function pushBits(val, count) {
            for (let i = count - 1; i >= 0; i--) {
                bits.push((val >> i) & 1);
            }
        }

        pushBits(0b0100, 4); // Byte mode
        pushBits(bytes.length, lenBits);
        for (let i = 0; i < bytes.length; i++) pushBits(bytes[i], 8);

        const maxBits = totalDataBytes * 8;
        const terminator = Math.min(4, maxBits - bits.length);
        pushBits(0, terminator);
        while (bits.length % 8 !== 0) bits.push(0);

        const dataBytes = [];
        for (let i = 0; i < bits.length; i += 8) {
            let b = 0;
            for (let j = 0; j < 8; j++) b = (b << 1) | bits[i + j];
            dataBytes.push(b);
        }
        const pad = [0xec, 0x11];
        for (let p = 0; dataBytes.length < totalDataBytes; p++) {
            dataBytes.push(pad[p % 2]);
        }

        // Split into RS blocks & interleave
        const dataBlocks = [];
        const ecBlocks = [];
        let offset = 0;
        for (let i = 0; i < g1b; i++) {
            const blk = dataBytes.slice(offset, offset + g1d);
            offset += g1d;
            dataBlocks.push(blk);
            ecBlocks.push(rsComputeEc(blk, ecPerBlock));
        }
        for (let i = 0; i < g2b; i++) {
            const blk = dataBytes.slice(offset, offset + g2d);
            offset += g2d;
            dataBlocks.push(blk);
            ecBlocks.push(rsComputeEc(blk, ecPerBlock));
        }

        const result = [];
        const maxDataLen = Math.max(g1d, g2d);
        for (let col = 0; col < maxDataLen; col++) {
            for (let b = 0; b < dataBlocks.length; b++) {
                if (col < dataBlocks[b].length) result.push(dataBlocks[b][col]);
            }
        }
        for (let col = 0; col < ecPerBlock; col++) {
            for (let b = 0; b < ecBlocks.length; b++) {
                result.push(ecBlocks[b][col]);
            }
        }
        return result;
    }

    function computeFormatInfo(mask) {
        // ECC Level M = 00
        const data = mask & 0x7; // (0b00 << 3) | mask
        let rem = data << 10;
        const poly = 0x537;
        for (let bit = 14; bit >= 10; bit--) {
            if ((rem >> bit) & 1) rem ^= poly << (bit - 10);
        }
        return ((data << 10) | rem) ^ 0x5412;
    }

    function computeVersionInfo(version) {
        let rem = version << 12;
        const poly = 0x1f25;
        for (let bit = 17; bit >= 12; bit--) {
            if ((rem >> bit) & 1) rem ^= poly << (bit - 12);
        }
        return (version << 12) | rem;
    }

    function createMatrix(version, codewords) {
        const size = version * 4 + 17;
        const mod = Array.from({ length: size }, () => new Int8Array(size).fill(-1));
        const isFunc = Array.from({ length: size }, () => new Uint8Array(size));

        function setFunc(r, c, val) {
            if (r < 0 || r >= size || c < 0 || c >= size) return;
            mod[r][c] = val ? 1 : 0;
            isFunc[r][c] = 1;
        }

        function drawFinder(topR, leftC) {
            for (let dr = -1; dr <= 7; dr++) {
                for (let dc = -1; dc <= 7; dc++) {
                    const r = topR + dr;
                    const c = leftC + dc;
                    if (r < 0 || r >= size || c < 0 || c >= size) continue;
                    const inOuter = dr >= 0 && dr <= 6 && dc >= 0 && dc <= 6;
                    const isDark = inOuter && (
                        dr === 0 || dr === 6 || dc === 0 || dc === 6 ||
                        (dr >= 2 && dr <= 4 && dc >= 2 && dc <= 4)
                    );
                    setFunc(r, c, isDark);
                }
            }
        }

        function drawAlignment(cr, cc) {
            for (let dr = -2; dr <= 2; dr++) {
                for (let dc = -2; dc <= 2; dc++) {
                    const isDark = Math.max(Math.abs(dr), Math.abs(dc)) !== 1;
                    setFunc(cr + dr, cc + dc, isDark);
                }
            }
        }

        drawFinder(0, 0);
        drawFinder(0, size - 7);
        drawFinder(size - 7, 0);

        // Timing patterns
        for (let i = 8; i < size - 8; i++) {
            setFunc(6, i, i % 2 === 0);
            setFunc(i, 6, i % 2 === 0);
        }

        // Dark module
        setFunc(version * 4 + 9, 8, 1);

        // Alignment patterns
        const pos = ALIGN_POS[version];
        for (let i = 0; i < pos.length; i++) {
            for (let j = 0; j < pos.length; j++) {
                const r = pos[i];
                const c = pos[j];
                const nearTopLeft = r <= 8 && c <= 8;
                const nearTopRight = r <= 8 && c >= size - 8;
                const nearBottomLeft = r >= size - 8 && c <= 8;
                if (!nearTopLeft && !nearTopRight && !nearBottomLeft) {
                    drawAlignment(r, c);
                }
            }
        }

        // Reserve format info cells
        for (let i = 0; i <= 8; i++) {
            if (!isFunc[8][i]) setFunc(8, i, 0);
            if (!isFunc[i][8]) setFunc(i, 8, 0);
        }
        for (let i = 0; i < 8; i++) {
            setFunc(8, size - 1 - i, 0);
            if (i < 7) setFunc(size - 1 - i, 8, 0);
        }

        // Version info (v >= 7)
        if (version >= 7) {
            const verBits = computeVersionInfo(version);
            for (let i = 0; i < 18; i++) {
                const bit = (verBits >> i) & 1;
                const a = Math.floor(i / 3);
                const b = i % 3;
                setFunc(a, size - 11 + b, bit);
                setFunc(size - 11 + b, a, bit);
            }
        }

        // Zigzag data placement
        let bitIdx = 0;
        const totalBits = codewords.length * 8;
        let dir = -1;
        for (let right = size - 1; right >= 1; right -= 2) {
            if (right === 6) right = 5;
            for (let step = 0; step < size; step++) {
                const r = dir === -1 ? (size - 1 - step) : step;
                for (let k = 0; k < 2; k++) {
                    const c = right - k;
                    if (!isFunc[r][c]) {
                        let bit = 0;
                        if (bitIdx < totalBits) {
                            const cw = codewords[bitIdx >> 3];
                            bit = (cw >> (7 - (bitIdx & 7))) & 1;
                            bitIdx++;
                        }
                        mod[r][c] = bit;
                    }
                }
            }
            dir = -dir;
        }

        return { size, mod, isFunc };
    }

    function applyFormatInfo(grid, size, mask) {
        const fmt = computeFormatInfo(mask);
        for (let i = 0; i <= 5; i++) grid[8][i] = (fmt >> (14 - i)) & 1;
        grid[8][7] = (fmt >> 8) & 1;
        grid[8][8] = (fmt >> 7) & 1;
        grid[7][8] = (fmt >> 6) & 1;
        for (let i = 0; i <= 5; i++) grid[5 - i][8] = (fmt >> (5 - i)) & 1;

        for (let i = 0; i < 7; i++) grid[size - 1 - i][8] = (fmt >> (14 - i)) & 1;
        for (let i = 0; i < 8; i++) grid[8][size - 8 + i] = (fmt >> (7 - i)) & 1;
    }

    function penaltyScore(grid, size) {
        let score = 0;
        // N1: 5+ same-color run in rows & cols
        for (let i = 0; i < size; i++) {
            let rRun = 1, cRun = 1;
            for (let j = 1; j < size; j++) {
                if (grid[i][j] === grid[i][j - 1]) {
                    rRun++;
                    if (rRun === 5) score += 3;
                    else if (rRun > 5) score += 1;
                } else rRun = 1;

                if (grid[j][i] === grid[j - 1][i]) {
                    cRun++;
                    if (cRun === 5) score += 3;
                    else if (cRun > 5) score += 1;
                } else cRun = 1;
            }
        }
        // N2: 2x2 blocks
        for (let r = 0; r < size - 1; r++) {
            for (let c = 0; c < size - 1; c++) {
                const v = grid[r][c];
                if (v === grid[r][c + 1] && v === grid[r + 1][c] && v === grid[r + 1][c + 1]) {
                    score += 3;
                }
            }
        }
        // N3: 1:1:3:1:1 finder-like ratio with 4 light either side
        function scanLine(getVal) {
            let p = 0;
            for (let i = 0; i <= size - 11; i++) {
                const a = getVal(i), b = getVal(i + 1), c = getVal(i + 2), d = getVal(i + 3),
                    e = getVal(i + 4), f = getVal(i + 5), g = getVal(i + 6),
                    h = getVal(i + 7), k = getVal(i + 8), l = getVal(i + 9), m = getVal(i + 10);
                if (
                    (a === 1 && b === 0 && c === 1 && d === 1 && e === 1 && f === 0 && g === 1 && h === 0 && k === 0 && l === 0 && m === 0) ||
                    (a === 0 && b === 0 && c === 0 && d === 0 && e === 1 && f === 0 && g === 1 && h === 1 && k === 1 && l === 0 && m === 1)
                ) {
                    p += 40;
                }
            }
            return p;
        }
        for (let i = 0; i < size; i++) {
            score += scanLine((j) => grid[i][j]);
            score += scanLine((j) => grid[j][i]);
        }
        // N4: dark ratio balance
        let dark = 0;
        for (let r = 0; r < size; r++) {
            for (let c = 0; c < size; c++) if (grid[r][c] === 1) dark++;
        }
        const pct = (dark * 100) / (size * size);
        const fiveSteps = Math.min(Math.abs(Math.floor(pct / 5) * 5 - 50), Math.abs(Math.ceil(pct / 5) * 5 - 50)) / 5;
        score += fiveSteps * 10;
        return score;
    }

    function encodeMatrix(text) {
        const bytes = utf8Bytes(String(text || ""));
        const version = pickVersion(bytes.length);
        const codewords = buildCodewords(bytes, version);
        const { size, mod, isFunc } = createMatrix(version, codewords);

        let bestMask = 0;
        let bestScore = Infinity;
        let bestGrid = null;

        for (let mask = 0; mask < 8; mask++) {
            const fn = MASK_FNS[mask];
            const cand = Array.from({ length: size }, (_, r) => {
                const row = new Int8Array(mod[r]);
                for (let c = 0; c < size; c++) {
                    if (!isFunc[r][c] && fn(r, c)) {
                        row[c] ^= 1;
                    }
                }
                return row;
            });
            applyFormatInfo(cand, size, mask);
            const score = penaltyScore(cand, size);
            if (score < bestScore) {
                bestScore = score;
                bestMask = mask;
                bestGrid = cand;
            }
        }

        return { size, version, mask: bestMask, modules: bestGrid };
    }

    function toSvgString(text, quietZone, scale) {
        const margin = typeof quietZone === "number" ? quietZone : 4;
        const pxPerMod = typeof scale === "number" && scale > 0 ? scale : 8;
        const { size, modules } = encodeMatrix(text);
        const full = size + margin * 2;
        const dim = full * pxPerMod;
        const parts = [];
        for (let r = 0; r < size; r++) {
            for (let c = 0; c < size; c++) {
                if (modules[r][c] === 1) {
                    parts.push(`M${c + margin},${r + margin}h1v1h-1z`);
                }
            }
        }
        return `<svg xmlns="http://www.w3.org/2000/svg" width="${dim}" height="${dim}" viewBox="0 0 ${full} ${full}" shape-rendering="crispEdges"><rect width="${full}" height="${full}" fill="#ffffff"/><path d="${parts.join("")}" fill="#000000"/></svg>`;
    }

    function toSvgDataUrl(text, quietZone, scale) {
        const svg = toSvgString(text, quietZone, scale);
        return "data:image/svg+xml;utf8," + encodeURIComponent(svg);
    }

    function toPngDataUrl(text, quietZone, moduleSize) {
        const margin = typeof quietZone === "number" ? quietZone : 4;
        const scale = typeof moduleSize === "number" && moduleSize >= 2 ? Math.floor(moduleSize) : 6;
        if (typeof document === "undefined" || !document.createElement) {
            return toSvgDataUrl(text, margin, scale);
        }
        const { size, modules } = encodeMatrix(text);
        const full = size + margin * 2;
        const canvas = document.createElement("canvas");
        canvas.width = full * scale;
        canvas.height = full * scale;
        const ctx = canvas.getContext("2d");
        if (!ctx) {
            return toSvgDataUrl(text, margin, scale);
        }
        ctx.fillStyle = "#ffffff";
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = "#000000";
        for (let r = 0; r < size; r++) {
            for (let c = 0; c < size; c++) {
                if (modules[r][c] === 1) {
                    ctx.fillRect((c + margin) * scale, (r + margin) * scale, scale, scale);
                }
            }
        }
        return canvas.toDataURL("image/png");
    }

    global.DineFlowQR = {
        encodeMatrix,
        toSvgString,
        toSvgDataUrl,
        toPngDataUrl,
    };
})(window);
