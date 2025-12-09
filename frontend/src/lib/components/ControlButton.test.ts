import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/svelte';
import ControlButton from './ControlButton.svelte';

describe('ControlButton', () => {
	it('renders with label', () => {
		const { getByText } = render(ControlButton, {
			props: {
				label: 'Heater ON',
				icon: 'flame',
				onClick: vi.fn(),
			},
		});

		expect(getByText('Heater ON')).toBeTruthy();
	});

	it('calls onClick when clicked', async () => {
		const handleClick = vi.fn().mockResolvedValue(undefined);
		const { getByRole } = render(ControlButton, {
			props: {
				label: 'Heater ON',
				icon: 'flame',
				onClick: handleClick,
			},
		});

		await fireEvent.click(getByRole('button'));

		expect(handleClick).toHaveBeenCalledTimes(1);
	});

	it('shows loading state during async action', async () => {
		let resolvePromise: () => void;
		const pendingPromise = new Promise<void>((resolve) => {
			resolvePromise = resolve;
		});
		const handleClick = vi.fn().mockReturnValue(pendingPromise);

		const { getByRole, queryByText } = render(ControlButton, {
			props: {
				label: 'Heater ON',
				icon: 'flame',
				onClick: handleClick,
			},
		});

		await fireEvent.click(getByRole('button'));

		// Button should be disabled during loading
		expect(getByRole('button').hasAttribute('disabled')).toBe(true);

		// Resolve the promise
		resolvePromise!();
	});

	it('applies variant styling', () => {
		const { getByRole } = render(ControlButton, {
			props: {
				label: 'Heater ON',
				icon: 'flame',
				onClick: vi.fn(),
				variant: 'primary',
			},
		});

		// Primary variant should have orange accent
		const button = getByRole('button');
		expect(button.className).toContain('primary');
	});
});
