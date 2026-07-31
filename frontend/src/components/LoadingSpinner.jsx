export default function LoadingSpinner() {
  return (
    <div className="flex items-center justify-center min-h-screen bg-base">
      <div className="w-12 h-12 border-4 border-accent border-t-transparent rounded-full animate-spin"></div>
    </div>
  );
}
