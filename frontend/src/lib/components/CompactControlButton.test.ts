import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/svelte';
import CompactControlButton from './CompactControlButton.svelte';

describe('CompactControlButton', () => {
	describe('rendering', () => {
		it('renders with icon and label', () => {
			render(CompactControlButton, {
				props: {
					label: 'ON',
					icon: 'flame',
					onClick: vi.fn()
				}
			});

			expect(screen.getByText('ON')).toBeTruthy();
			expect(screen.getByRole('button')).toBeTruthy();
		});

		it('applies primary variant styles by default', () => {
			render(CompactControlButton, {
				props: {
					label: 'ON',
					icon: 'flame',
					onClick: vi.fn()
				}
			});

			const button = screen.getByRole('button');
			// Primary variant should have orange styling
			expect(button.className).toContain('orange');
		});

		it('applies secondary variant styles', () => {
			render(CompactControlButton, {
				props: {
					label: 'OFF',
					icon: 'flame-off',
					variant: 'secondary',
					onClick: vi.fn()
				}
			});

			const button = screen.getByRole('button');
			expect(button.className).toContain('blue');
		});

		it('applies tertiary variant styles', () => {
			render(CompactControlButton, {
				props: {
					label: 'PUMP',
					icon: 'refresh',
					variant: 'tertiary',
					onClick: vi.fn()
				}
			});

			const button = screen.getByRole('button');
			expect(button.className).toContain('cyan');
		});
	});

	describe('interactions', () => {
		it('calls onClick when clicked', async () => {
			const onClick = vi.fn().mockResolvedValue(undefined);
			render(CompactControlButton, {
				props: {
					label: 'ON',
					icon: 'flame',
					onClick
				}
			});

			const button = screen.getByRole('button');
			await fireEvent.click(button);

			expect(onClick).toHaveBeenCalled();
		});

		it('shows loading state during async onClick', async () => {
			let resolvePromise: () => void;
			const promise = new Promise<void>((resolve) => {
				resolvePromise = resolve;
			});
			const onClick = vi.fn().mockReturnValue(promise);

			render(CompactControlButton, {
				props: {
					label: 'ON',
					icon: 'flame',
					onClick
				}
			});

			const button = screen.getByRole('button');
			await fireEvent.click(button);

			// Button should be disabled during loading
			expect(button).toHaveProperty('disabled', true);

			// Resolve the promise
			resolvePromise!();
			await waitFor(() => {
				expect(button).toHaveProperty('disabled', false);
			});
		});

		it('does not call onClick when already loading', async () => {
			let resolvePromise: () => void;
			const promise = new Promise<void>((resolve) => {
				resolvePromise = resolve;
			});
			const onClick = vi.fn().mockReturnValue(promise);

			render(CompactControlButton, {
				props: {
					label: 'ON',
					icon: 'flame',
					onClick
				}
			});

			const button = screen.getByRole('button');

			// Click once to start loading
			await fireEvent.click(button);
			expect(onClick).toHaveBeenCalledTimes(1);

			// Try to click again while loading
			await fireEvent.click(button);
			expect(onClick).toHaveBeenCalledTimes(1); // Still only 1 call

			resolvePromise!();
		});
	});

	describe('tooltip', () => {
		it('renders with tooltip prop', () => {
			// Just verify the component accepts the tooltip prop without error
			render(CompactControlButton, {
				props: {
					label: 'ON',
					icon: 'flame',
					onClick: vi.fn(),
					tooltip: 'Turn on the heater'
				}
			});

			// Button should render successfully with tooltip prop
			expect(screen.getByRole('button')).toBeTruthy();
		});
	});
});
