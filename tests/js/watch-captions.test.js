import { beforeEach, describe, expect, it, vi } from 'vitest';
import { applyStyle, loadPrefs, savePrefs } from '../../resources/js/watch-captions.js';

/**
 * Caption preferences are an accessibility setting. Someone who needs large
 * high-contrast subtitles needs them on every film, so silently losing them —
 * or crashing on a corrupt value and falling back to nothing — is the failure
 * worth testing.
 */
describe('caption preferences', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('returns usable defaults when nothing is stored', () => {
        const prefs = loadPrefs();

        expect(prefs.size).toBe('medium');
        expect(prefs.color).toBe('#ffffff');
        expect(prefs.background).toBe('dim');
    });

    it('round-trips a saved preference', () => {
        savePrefs({ ...loadPrefs(), size: 'large', color: '#ffff00' });

        const prefs = loadPrefs();

        expect(prefs.size).toBe('large');
        expect(prefs.color).toBe('#ffff00');
    });

    it('fills in defaults for keys added after the prefs were saved', () => {
        // A stored blob from an older version must not leave new settings
        // undefined — that renders captions with "undefined" in the CSS.
        localStorage.setItem('soundchex.captions', JSON.stringify({ size: 'large' }));

        const prefs = loadPrefs();

        expect(prefs.size).toBe('large');
        expect(prefs.background).toBe('dim');
        expect(prefs.position).toBe(12);
    });

    it('falls back to defaults on corrupt stored JSON', () => {
        localStorage.setItem('soundchex.captions', 'not json{');

        expect(loadPrefs().size).toBe('medium');
    });

    it('does not throw when storage is unavailable', () => {
        // Private browsing throws on write. Captions should still play.
        const setItem = vi.spyOn(Storage.prototype, 'setItem')
            .mockImplementation(() => { throw new Error('QuotaExceededError'); });

        expect(() => savePrefs(loadPrefs())).not.toThrow();

        setItem.mockRestore();
    });
});

describe('applyStyle', () => {
    const style = (prefs) => {
        const overlay = document.createElement('div');
        applyStyle(overlay, { ...loadPrefs(), ...prefs });

        return overlay.style;
    };

    it('adds an outline when there is no box behind the text', () => {
        // With a transparent background the outline is the only thing keeping
        // text legible over a bright scene.
        expect(style({ background: 'none' }).getPropertyValue('--caption-shadow'))
            .toContain('rgba(0,0,0,0.95)');
        expect(style({ background: 'none' }).getPropertyValue('--caption-bg'))
            .toBe('transparent');
    });

    it('drops the outline when a solid box is behind the text', () => {
        expect(style({ background: 'solid' }).getPropertyValue('--caption-shadow')).toBe('none');
    });

    it('scales text with the chosen size', () => {
        const small = parseFloat(style({ size: 'small' }).getPropertyValue('--caption-size'));
        const large = parseFloat(style({ size: 'large' }).getPropertyValue('--caption-size'));

        expect(large).toBeGreaterThan(small);
    });

    it('falls back to a readable size for an unknown value', () => {
        // Never resolve to NaN — that produces invalid CSS and captions
        // vanish entirely.
        const size = style({ size: 'gigantic' }).getPropertyValue('--caption-size');

        expect(parseFloat(size)).toBeGreaterThan(0);
    });

    it('positions captions from the bottom', () => {
        expect(style({ position: 25 }).getPropertyValue('--caption-bottom')).toBe('25%');
    });
});
