const DatePicker = () => {
    // Get initial dates from URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    const adjustToMalaysianTime = (dateStr) => {
        if (!dateStr) return null;
        const date = new Date(dateStr);
        date.setHours(8, 0, 0, 0); // Set to 8 AM Malaysia time
        return date;
    };
    const initialStartDate = adjustToMalaysianTime(urlParams.get('start_date'));
    const initialEndDate = adjustToMalaysianTime(urlParams.get('end_date'));

    const [showPicker, setShowPicker] = React.useState(false);
    const [startDate, setStartDate] = React.useState(initialStartDate);
    const [endDate, setEndDate] = React.useState(initialEndDate);
    const [leftMonth, setLeftMonth] = React.useState(initialStartDate || new Date());
    const [rightMonth, setRightMonth] = React.useState(initialEndDate || new Date(new Date().setMonth(new Date().getMonth() + 1)));
    const pickerRef = React.useRef(null);
    const inputRef = React.useRef(null);
    const [isMobile, setIsMobile] = React.useState(window.innerWidth < 768);

    React.useEffect(() => {
        const handleResize = () => {
            setIsMobile(window.innerWidth < 768);
        };
        window.addEventListener('resize', handleResize);
        // Initial check in case the component mounts on a mobile-sized window
        handleResize();
        // Cleanup listener on component unmount
        return () => window.removeEventListener('resize', handleResize);
    }, []);

    React.useEffect(() => {
        const formatForInput = (date) => {
            if (!date) return '';
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        };
        document.querySelector('[name="start_date"]').value = formatForInput(startDate);
        document.querySelector('[name="end_date"]').value = formatForInput(endDate);
        
        const event = new Event('input', { bubbles: true });
        document.querySelector('[name="start_date"]').dispatchEvent(event);
        document.querySelector('[name="end_date"]').dispatchEvent(event);
    }, [startDate, endDate]);

    const formatDate = (date) => {
        if (!date) return 'Select dates';
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return `${months[date.getMonth()]} ${date.getDate()}`;
    };

    const getDisplayText = () => {
        if (!startDate) return 'Select dates';
        return endDate ? `${formatDate(startDate)} — ${formatDate(endDate)}` : 'Select end date';
    };

    const handleDateSelect = (day, monthDate, panelType) => {
        const selectedDate = new Date(monthDate.getFullYear(), monthDate.getMonth(), day);
        selectedDate.setHours(8, 0, 0, 0);

        if (panelType === 'start') {
            if (endDate && selectedDate > endDate) {
                // New start date after existing end date - reset both
                setStartDate(selectedDate);
                setEndDate(null);
            } else {
                setStartDate(selectedDate);
                // Auto-advance end panel if selecting same month
                if (!endDate && selectedDate.getMonth() === rightMonth.getMonth()) {
                    setRightMonth(new Date(selectedDate.setMonth(selectedDate.getMonth() + 1)));
                }
            }
        } else {
            if (!startDate || selectedDate < startDate) return;
            setEndDate(selectedDate);
        }
    };

    const generateCalendarDays = (monthDate, panelType) => {
        const days = [];
        const firstDay = new Date(monthDate.getFullYear(), monthDate.getMonth(), 1).getDay();
        const lastDay = new Date(monthDate.getFullYear(), monthDate.getMonth() + 1, 0).getDate();
        const startPadding = firstDay === 0 ? 6 : firstDay - 1;

        // Add empty cells for padding
        for (let i = 0; i < startPadding; i++) {
            days.push(React.createElement('div', { key: `pad-${i}` }));
        }

        // Generate calendar days
        const today = new Date();
        today.setHours(8, 0, 0, 0);
        const currentMonth = new Date().getMonth();
        
        for (let i = 1; i <= lastDay; i++) {
            const date = new Date(monthDate.getFullYear(), monthDate.getMonth(), i);
            date.setHours(8, 0, 0, 0);
            
            const isStart = startDate && date.getTime() === startDate.getTime();
            const isEnd = endDate && date.getTime() === endDate.getTime();
            const isInRange = startDate && endDate && date > startDate && date < endDate;
            const isToday = date.getTime() === today.getTime();
            
            // Disable dates based on panel type
            const isDisabled = panelType === 'start' 
                ? (endDate && date > endDate) 
                : (!startDate || date < startDate);

            let buttonClassName = [
                'relative',
                'w-10', // Slightly wider
                'h-10', // Slightly taller
                'flex',
                'items-center',
                'justify-center',
                'text-sm', // Keep text size reasonable
                'transition-all',
                'duration-200',
                'font-medium',
                'rounded-full',
                'group'
            ];

            if (isDisabled) {
                buttonClassName.push('text-gray-300 cursor-not-allowed');
            } else if (isStart || isEnd) {
                buttonClassName.push('bg-purple-600 text-white hover:bg-purple-700 z-10 shadow-md');
                if (isStart) buttonClassName.push('ml-auto');
                if (isEnd) buttonClassName.push('mr-auto');
            } else if (isInRange) {
                buttonClassName.push(
                    'bg-purple-50 text-purple-900 hover:bg-purple-100',
                    panelType === 'start' ? 'mr-auto' : 'ml-auto',
                    'before:absolute before:inset-y-0 before:w-full',
                    'before:bg-purple-50 before:-z-10'
                );
            } else {
                buttonClassName.push('text-gray-700 hover:bg-purple-50');
            }

            if (date.getMonth() !== monthDate.getMonth()) {
                buttonClassName.push('opacity-50');
            }

            if (isToday) {
                buttonClassName.push('font-semibold', !isStart && !isEnd ? 'text-purple-600' : '');
            }

            days.push(React.createElement('button', {
                type: 'button',
                key: i,
                onClick: () => !isDisabled && handleDateSelect(i, monthDate, panelType),
                className: buttonClassName.join(' '),
                disabled: isDisabled
            }, [
                React.createElement('span', { 
                    key: 'text',
                    className: 'relative'
                }, i),
                isToday && React.createElement('span', {
                    key: 'dot',
                    className: `absolute bottom-1 w-1 h-1 rounded-full ${
                        isStart || isEnd ? 'bg-white' : 'bg-purple-400'
                    }`
                })
            ]));
        }

        return days;
    };

    const formatMonthYear = (date) => {
        const months = ['January', 'February', 'March', 'April', 'May', 'June', 
                        'July', 'August', 'September', 'October', 'November', 'December'];
        return `${months[date.getMonth()]} ${date.getFullYear()}`;
    };
    
    const renderMonthPanel = (monthDate, panelType) => React.createElement('div',
        { className: 'p-4 w-full sm:w-72' }, // Panel container (previous fix applied)
        [
            React.createElement('div', {
                key: 'header',
                // Changed to grid layout with 3 columns
                className: 'grid grid-cols-3 items-center mb-5' 
            }, [
                // Prev Button - First column, align content left
                React.createElement('div', { className: 'text-left' }, 
                    React.createElement('button', { 
                        type: 'button',
                        onClick: () => {
                            const newDate = new Date(monthDate);
                            newDate.setMonth(newDate.getMonth() - 1);
                            panelType === 'start' ? setLeftMonth(newDate) : setRightMonth(newDate);
                        },
                        className: 'p-2 hover:bg-purple-50 rounded-full transition-colors text-gray-600 hover:text-purple-700 inline-flex' // Added inline-flex
                    }, React.createElement('svg', {
                        className: 'w-5 h-5', fill: 'none', viewBox: '0 0 24 24', stroke: 'currentColor'
                    }, React.createElement('path', { strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 2, d: 'M15 19l-7-7 7-7' })))
                ),
                // Center Text Block - Second column, center text within it
                React.createElement('div', { className: 'text-center' }, [ 
                    React.createElement('span', { // Title: START/END DATE
                        className: 'block text-xs font-medium text-purple-600 mb-1 uppercase tracking-wide' // Added block
                    }, panelType === 'start' ? 'Start Date' : 'End Date'),
                    React.createElement('h2', { // Month Year
                        className: 'text-lg font-semibold text-gray-800'
                    }, formatMonthYear(monthDate))
                ]),
                // Next Button - Third column, align content right
                React.createElement('div', { className: 'text-right' }, 
                    React.createElement('button', { 
                        type: 'button',
                        onClick: () => {
                            const newDate = new Date(monthDate);
                            newDate.setMonth(newDate.getMonth() + 1);
                            panelType === 'start' ? setLeftMonth(newDate) : setRightMonth(newDate);
                        },
                        className: 'p-2 hover:bg-purple-50 rounded-full transition-colors text-gray-600 hover:text-purple-700 inline-flex' // Added inline-flex
                    }, React.createElement('svg', {
                        className: 'w-5 h-5', fill: 'none', viewBox: '0 0 24 24', stroke: 'currentColor'
                    }, React.createElement('path', { strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 2, d: 'M9 5l7 7-7 7' })))
                )
            ]),
            React.createElement('div', { // Weekdays
                key: 'weekdays',
                className: 'grid grid-cols-7 mb-3' // Removed px-1 for better alignment with days grid
            }, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(day =>
                React.createElement('div', {
                    key: day,
                    className: 'text-center text-xs font-medium text-gray-500 uppercase tracking-wider'
                }, day)
            )),
            React.createElement('div', { // Days Grid
                key: 'days',
                className: 'grid grid-cols-7 gap-y-1 gap-x-0' // Previous fix applied here
            }, generateCalendarDays(monthDate, panelType))
        ]
    );

    const calendar = showPicker && React.createElement('div', {
        ref: pickerRef,
        // Main dropdown container: Absolute positioning.
        // Mobile: Pin edges close to viewport, auto width. Desktop: centered, fixed width.
        // Kept max-height and overflow.
        className: `absolute mt-2 bg-white rounded-xl shadow-lg border border-gray-100 z-[9999] overflow-y-auto max-h-[85vh] ${
            isMobile
                ? 'left-2 right-2 w-auto' // Mobile: Pin edges near viewport sides, width adjusts
                : 'left-1/2 transform -translate-x-1/2 w-[740px]' // Desktop: centered, fixed width
        }`,
        style: {
            top: 'calc(100% + 8px)',
            filter: 'drop-shadow(0 12px 24px rgba(0,0,0,0.05))'
        }
    }, [
        // Container for the two month panels
        React.createElement('div', {
            className: `flex ${
                isMobile
                    ? 'flex-col divide-y divide-gray-100' // Mobile: Stack vertically
                    : 'flex-row divide-x divide-gray-100' // Desktop: Side-by-side
            }`
        }, [
            renderMonthPanel(leftMonth, 'start'),
            renderMonthPanel(rightMonth, 'end') // Both panels always rendered
        ]),
        // Footer section
        React.createElement('div', {
            className: 'flex flex-wrap sm:flex-nowrap justify-between items-center gap-3 px-4 sm:px-6 py-4 bg-gray-50 border-t border-gray-100 rounded-b-xl sticky bottom-0' // Sticky footer
        }, [
             // Today Button
             React.createElement('button', {
                type: 'button',
                onClick: () => {
                    const today = new Date();
                    today.setHours(8, 0, 0, 0);
                    setLeftMonth(new Date(today));
                    setRightMonth(new Date(new Date(today).setMonth(today.getMonth() + 1)));
                 },
                 className: 'order-2 sm:order-1 px-3 py-1.5 sm:px-4 sm:py-2 text-sm font-medium text-purple-600 hover:text-purple-700 transition-colors flex items-center gap-2 rounded-lg hover:bg-purple-50'
             }, [
                 React.createElement('svg', { className:'w-4 h-4', fill:'none', stroke:'currentColor', viewBox:'0 0 24 24' }, React.createElement('path', { strokeLinecap:'round', strokeLinejoin:'round', strokeWidth:2, d:'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' })),
                 'Today'
            ]),
            // Container for Clear/Apply buttons
            React.createElement('div', {
                className: 'order-1 sm:order-2 flex w-full sm:w-auto space-x-3'
            }, [
                 React.createElement('button', { // Clear button
                     type: 'button',
                     onClick: () => {
                         setStartDate(null);
                         setEndDate(null);
                         const today = new Date();
                         today.setHours(8, 0, 0, 0);
                         setLeftMonth(new Date(today));
                         setRightMonth(new Date(new Date(today).setMonth(today.getMonth() + 1)));
                     },
                     className: 'flex-1 sm:flex-none px-3 py-1.5 sm:px-4 sm:py-2 text-sm font-medium text-gray-600 hover:text-gray-800 transition-colors hover:bg-gray-100 rounded-lg'
                 }, 'Clear'),
                 React.createElement('button', { // Apply Dates button
                     type: 'button',
                     onClick: () => setShowPicker(false),
                     className: 'flex-1 sm:flex-none px-3 py-1.5 sm:px-4 sm:py-2 text-sm font-medium bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors shadow-sm hover:shadow-md'
                 }, 'Apply Dates')
            ])
        ])
    ]);

    return React.createElement('div', {
        className: 'relative inline-block w-full' // Outer container, keep relative
    }, [
        // This div is the clickable element now
        React.createElement('div', {
            ref: inputRef,
            onClick: () => setShowPicker(!showPicker),
            className: `flex items-center gap-3 px-4 py-2.5 text-sm bg-white border ${ // Adjusted padding
                showPicker ? 'border-purple-500 ring-2 ring-purple-100' : 'border-gray-200 hover:border-gray-300'
            } rounded-lg cursor-pointer transition-all duration-200 shadow-sm text-gray-600 w-full sm:w-auto justify-center sm:justify-start` // Ensure proper width/alignment
        }, [
            React.createElement('svg', { // Calendar Icon
                className: 'w-5 h-5 text-gray-400 flex-shrink-0',
                fill: 'none',
                stroke: 'currentColor',
                viewBox: '0 0 24 24'
            }, React.createElement('path', {
                strokeLinecap: 'round',
                strokeLinejoin: 'round',
                strokeWidth: 2,
                d: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'
            })),
            React.createElement('span', { // Text Display
                className: 'whitespace-nowrap font-medium' // Removed id
            }, getDisplayText()),
            // Optional: Add dropdown arrow if desired
            React.createElement('svg', {
                 className: 'w-4 h-4 text-gray-400 ml-auto sm:ml-2 flex-shrink-0',
                 fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24'
            }, React.createElement('path', { strokeLinecap: 'round', strokeLinejoin: 'round', strokeWidth: 2, d: 'M19 9l-7 7-7 7' }))
        ]),
        calendar // The dropdown calendar part
    ]);
};

const root = document.getElementById('date-picker-root');
ReactDOM.createRoot(root).render(React.createElement(DatePicker));