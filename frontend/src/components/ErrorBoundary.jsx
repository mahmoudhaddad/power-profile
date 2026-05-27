import { Component } from 'react';

export default class ErrorBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError() {
    return { hasError: true };
  }

  componentDidCatch() {}

  render() {
    if (!this.state.hasError) return this.props.children;

    if (this.props.fallback) return this.props.fallback;

    const label = this.props.label ? `"${this.props.label}"` : 'this section';

    return (
      <div className="flex flex-col items-center justify-center gap-3 py-10 px-6 text-center bg-white rounded-xl border border-gray-200 shadow-sm">
        <svg className="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
            d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
        </svg>
        <p className="text-sm text-gray-500">
          Something went wrong loading {label}.
        </p>
        <button
          onClick={() => this.setState({ hasError: false })}
          className="text-xs font-medium text-blue-600 hover:text-blue-800 px-3 py-1.5
            rounded-lg border border-blue-200 hover:bg-blue-50 transition-colors">
          Try again
        </button>
      </div>
    );
  }
}
