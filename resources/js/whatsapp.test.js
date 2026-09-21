import { describe, expect, it } from 'vitest';
import shared from '../../tests/whatsapp-numbers.json';
import { whatsappNumber } from './whatsapp';

/*
 * The browser's WhatsApp number rule, held to the same cases as the server's
 * (tests/Unit/WhatsAppTest.php reads the same file). The two draw the links on
 * one page — the statement's header from PHP, its reminder button from here —
 * and they must never disagree about which number a chat opens on.
 */
describe('the number rule the server shares', () => {
    it.each(shared.cases)('reads %j as %j', (written, expected) => {
        expect(whatsappNumber(written)).toBe(expected);
    });
});
